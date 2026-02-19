<?php

declare(strict_types=1);

namespace Cppc\CodeGen;

class AllocationResult
{
    /**
     * @param array<int, string> $regMap     vreg ID → physical register name
     * @param array<int, int>    $spillSlots vreg ID → stack byte offset (positive, from rbp)
     * @param int                $spillCount number of spilled vregs
     */
    public function __construct(
        public readonly array $regMap,
        public readonly array $spillSlots,
        public readonly int $spillCount,
    ) {}

    /**
     * Returns the assembly operand string for the given virtual register.
     * Either a register name like "rbx" or a memory reference like "[rbp-24]".
     */
    public function getLocation(int $vregId): string
    {
        if (isset($this->regMap[$vregId])) {
            return $this->regMap[$vregId];
        }

        if (isset($this->spillSlots[$vregId])) {
            return '[rbp-' . $this->spillSlots[$vregId] . ']';
        }

        // Should not happen with a well-formed IR, but return a placeholder so
        // callers can at least emit something diagnosable.
        return '[rbp-??vreg' . $vregId . ']';
    }
}
