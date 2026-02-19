<?php

declare(strict_types=1);

namespace Cppc\CodeGen;

use Cppc\IR\IRFunction;
use Cppc\IR\Instruction;
use Cppc\IR\Operand;
use Cppc\IR\OperandKind;
use Cppc\IR\BasicBlock;
use Cppc\IR\OpCode;

class RegisterAllocator
{
    /**
     * General-purpose registers available for allocation.
     * Callee-saved registers are listed first so that longer-lived intervals
     * prefer them and reduce save/restore overhead at call sites.
     */
    private const GP_REGS = ['rbx', 'r12', 'r13', 'r14', 'r15', 'r10', 'r11'];

    /**
     * XMM registers available for float allocation.
     * xmm0 is reserved as the float return register.
     */
    private const FP_REGS = ['xmm1', 'xmm2', 'xmm3', 'xmm4', 'xmm5', 'xmm6', 'xmm7'];

    public function allocate(IRFunction $func): AllocationResult
    {
        $intervals = $this->computeLiveIntervals($func);

        // Sort by start position (linear-scan order).
        usort($intervals, static fn(LiveInterval $a, LiveInterval $b) => $a->start <=> $b->start);

        return $this->linearScan($intervals);
    }

    /**
     * Assigns a monotonically increasing "program point" to every instruction
     * across all basic blocks, then for each virtual register records the
     * earliest definition and the latest use.
     *
     * @return LiveInterval[]
     */
    private function computeLiveIntervals(IRFunction $func): array
    {
        /** @var array<int, LiveInterval> $intervals  keyed by vreg id */
        $intervals = [];

        // We need to know whether a vreg holds a float value so we can assign
        // it to an XMM register instead of a GP register.
        $isFloatVreg = $this->detectFloatVregs($func);

        $position = 0;

        foreach ($func->blocks as $block) {
            foreach ($block->instructions as $inst) {
                // Uses — extend end of live interval.
                foreach ($inst->usesRegisters() as $vregId) {
                    if (isset($intervals[$vregId])) {
                        $intervals[$vregId]->end = max($intervals[$vregId]->end, $position);
                    } else {
                        // Use before def (e.g. phi or parameter) — treat as born at 0.
                        $intervals[$vregId] = new LiveInterval(
                            vregId : $vregId,
                            start  : 0,
                            end    : $position,
                            isFloat: $isFloatVreg[$vregId] ?? false,
                        );
                    }
                }

                // Definition — open a new interval.
                $def = $inst->definesRegister();
                if ($def !== null) {
                    if (!isset($intervals[$def])) {
                        $intervals[$def] = new LiveInterval(
                            vregId : $def,
                            start  : $position,
                            end    : $position,
                            isFloat: $isFloatVreg[$def] ?? false,
                        );
                    } else {
                        // Redefinition — update start only if earlier.
                        $intervals[$def]->start = min($intervals[$def]->start, $position);
                    }
                }

                $position++;
            }
        }

        return array_values($intervals);
    }

    /**
     * Scans the IR to determine which virtual registers carry floating-point
     * values based on the opcode that defines them.
     *
     * @return array<int, bool>  vreg id → true if float
     */
    private function detectFloatVregs(IRFunction $func): array
    {
        $floatVregs = [];

        foreach ($func->blocks as $block) {
            foreach ($block->instructions as $inst) {
                $def = $inst->definesRegister();
                if ($def === null) {
                    continue;
                }

                $floatVregs[$def] = match ($inst->opcode) {
                    \Cppc\IR\OpCode::FAdd,
                    \Cppc\IR\OpCode::FSub,
                    \Cppc\IR\OpCode::FMul,
                    \Cppc\IR\OpCode::FDiv,
                    \Cppc\IR\OpCode::FNeg,
                    \Cppc\IR\OpCode::IntToFloat  => true,
                    \Cppc\IR\OpCode::LoadFloat   => true,
                    // FCmp* and FloatToInt produce integer results.
                    default => false,
                };

                // If a source of a Move is known to be float, propagate.
                if ($inst->opcode === \Cppc\IR\OpCode::Move
                    && $inst->src1?->kind === OperandKind::VirtualReg) {
                    $floatVregs[$def] = $floatVregs[$inst->src1->value] ?? false;
                }
            }
        }

        return $floatVregs;
    }

    private function linearScan(array $intervals): AllocationResult
    {
        /** @var array<string, bool> $freeGP   physical reg → available */
        $freeGP = array_fill_keys(self::GP_REGS, true);
        /** @var array<string, bool> $freeFP */
        $freeFP = array_fill_keys(self::FP_REGS, true);

        /** @var array<int, string>  $regMap   vreg → physical reg */
        $regMap = [];
        /** @var array<int, int>     $spillSlots vreg → rbp offset */
        $spillSlots = [];
        $spillOffset = 0;   // grows downward; stored as positive offset from rbp

        /** @var LiveInterval[] $active  currently live, sorted by end point */
        $active = [];

        foreach ($intervals as $current) {
            // Expire old intervals that ended before current starts.
            $active = $this->expireOldIntervals($active, $current, $freeGP, $freeFP, $regMap);

            $pool    = $current->isFloat ? $freeFP : $freeGP;
            $freeReg = $this->pickFreeReg($pool);

            if ($freeReg !== null) {
                // Assign the physical register.
                $regMap[$current->vregId] = $freeReg;
                if ($current->isFloat) {
                    $freeFP[$freeReg] = false;
                } else {
                    $freeGP[$freeReg] = false;
                }
                $active[] = $current;
                // Keep active sorted by end point for efficient expiry.
                usort($active, static fn(LiveInterval $a, LiveInterval $b) => $a->end <=> $b->end);
            } else {
                // Spill: evict the interval with the latest end point.
                $spillOffset += 8;
                $victim = $this->findSpillVictim($active, $current);

                if ($victim !== null && $victim->end > $current->end) {
                    // Spill the victim; give its register to current.
                    $victimReg                       = $regMap[$victim->vregId];
                    $spillSlots[$victim->vregId]     = $spillOffset;
                    unset($regMap[$victim->vregId]);
                    $regMap[$current->vregId]        = $victimReg;

                    // Remove victim from active; add current.
                    $active = array_values(array_filter(
                        $active,
                        static fn(LiveInterval $i) => $i->vregId !== $victim->vregId,
                    ));
                    $active[] = $current;
                    usort($active, static fn(LiveInterval $a, LiveInterval $b) => $a->end <=> $b->end);
                } else {
                    // Spill current.
                    $spillSlots[$current->vregId] = $spillOffset;
                }
            }
        }

        return new AllocationResult(
            regMap    : $regMap,
            spillSlots: $spillSlots,
            spillCount: count($spillSlots),
        );
    }

    /**
     * Removes intervals from $active whose end point is strictly before
     * $current->start, freeing their registers.
     *
     * @param  LiveInterval[]              $active
     * @param  array<string, bool>         $freeGP  passed by reference
     * @param  array<string, bool>         $freeFP  passed by reference
     * @param  array<int, string>          $regMap
     * @return LiveInterval[]
     */
    private function expireOldIntervals(
        array $active,
        LiveInterval $current,
        array &$freeGP,
        array &$freeFP,
        array $regMap,
    ): array {
        $remaining = [];

        foreach ($active as $interval) {
            if ($interval->end < $current->start) {
                // Free its register.
                if (isset($regMap[$interval->vregId])) {
                    $reg = $regMap[$interval->vregId];
                    if ($interval->isFloat) {
                        $freeFP[$reg] = true;
                    } else {
                        $freeGP[$reg] = true;
                    }
                }
            } else {
                $remaining[] = $interval;
            }
        }

        return $remaining;
    }

    /** @param array<string, bool> $pool */
    private function pickFreeReg(array $pool): ?string
    {
        foreach ($pool as $reg => $free) {
            if ($free) {
                return $reg;
            }
        }
        return null;
    }

    /**
     * Among currently active intervals (of the same float/int class as $current),
     * returns the one with the latest end point — a good spill candidate.
     *
     * @param  LiveInterval[] $active
     */
    private function findSpillVictim(array $active, LiveInterval $current): ?LiveInterval
    {
        $candidate = null;

        foreach ($active as $interval) {
            if ($interval->isFloat !== $current->isFloat) {
                continue;
            }
            if ($candidate === null || $interval->end > $candidate->end) {
                $candidate = $interval;
            }
        }

        return $candidate;
    }
}
