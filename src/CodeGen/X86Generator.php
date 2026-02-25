<?php

declare(strict_types=1);

namespace Cppc\CodeGen;

use Cppc\IR\{IRModule, IRFunction, IRGlobal, BasicBlock, Instruction, OpCode, Operand, OperandKind};
use Cppc\CompileError;

/**
 * Translates the IR of an entire module into GAS AT&T-syntax x86-64 assembly.
 *
 * Translation flow per function
 * ─────────────────────────────
 * 1. Register allocation (linear scan)
 * 2. Frame size calculation (spills + locals, 16-byte aligned)
 * 3. Prologue: push %rbp, mov %rsp → %rbp, sub $frame, %rsp, save callee-saved
 * 4. Copy ABI argument registers to local stack slots
 * 5. Translate every basic block instruction by instruction
 * 6. Epilogue: restore callee-saved, mov %rbp → %rsp, pop %rbp, ret
 */
class X86Generator
{
    private AsmEmitter       $emitter;
    private RegisterAllocator $allocator;
    private CallingConvention $convention;

    private ?AllocationResult $allocation = null;
    private ?IRFunction $currentFunc = null;
    private string $epilogueLabel = '';
    private array $usedCalleeSaved = [];
    private int $frameSize = 0;
    private int $floatConstCounter = 0;
    private bool $picMode = false;

    /** Float constants emitted to .rodata: label → raw bit pattern. */
    private array $floatConsts = [];
    /** @var array<string, true> Local function names in this module */
    private array $localFunctions = [];

    /**
     * Maps a 64-bit GP register to its sub-register names.
     * Index: 0 = 64-bit, 1 = 32-bit, 2 = 16-bit, 3 = 8-bit.
     *
     * @var array<string, string[]>
     */
    private const REG_SIZES = [
        'rax'  => ['rax',  'eax',  'ax',   'al'],
        'rbx'  => ['rbx',  'ebx',  'bx',   'bl'],
        'rcx'  => ['rcx',  'ecx',  'cx',   'cl'],
        'rdx'  => ['rdx',  'edx',  'dx',   'dl'],
        'rsi'  => ['rsi',  'esi',  'si',   'sil'],
        'rdi'  => ['rdi',  'edi',  'di',   'dil'],
        'rbp'  => ['rbp',  'ebp',  'bp',   'bpl'],
        'rsp'  => ['rsp',  'esp',  'sp',   'spl'],
        'r8'   => ['r8',   'r8d',  'r8w',  'r8b'],
        'r9'   => ['r9',   'r9d',  'r9w',  'r9b'],
        'r10'  => ['r10',  'r10d', 'r10w', 'r10b'],
        'r11'  => ['r11',  'r11d', 'r11w', 'r11b'],
        'r12'  => ['r12',  'r12d', 'r12w', 'r12b'],
        'r13'  => ['r13',  'r13d', 'r13w', 'r13b'],
        'r14'  => ['r14',  'r14d', 'r14w', 'r14b'],
        'r15'  => ['r15',  'r15d', 'r15w', 'r15b'],
    ];

    public function __construct(bool $picMode = false)
    {
        $this->emitter    = new AsmEmitter();
        $this->allocator  = new RegisterAllocator();
        $this->convention = new CallingConvention();
        $this->picMode    = $picMode;
    }

    public function generate(IRModule $module): string
    {
        $this->emitter->reset();
        $this->floatConsts       = [];
        $this->floatConstCounter = 0;
        $this->localFunctions    = [];
        foreach ($module->functions as $func) {
            $this->localFunctions[$func->name] = true;
        }

        // ── .data ──────────────────────────────────────────────────────────
        $initGlobals = array_filter(
            $module->globals,
            static fn(IRGlobal $g) => $g->initValue !== null,
        );
        if ($initGlobals !== []) {
            $this->emitter->data();
            $this->emitter->align(8);
            foreach ($initGlobals as $global) {
                if (!$global->isLocal) {
                    $this->emitter->global($global->name);
                }
                $this->emitter->type($global->name, 'object');
                $this->emitter->label($global->name);
                $this->emitGlobalData($global);
                $this->emitter->size($global->name, $global->size . '');
                $this->emitter->blank();
            }
        }

        // ── .bss ───────────────────────────────────────────────────────────
        $bssGlobals = array_filter(
            $module->globals,
            static fn(IRGlobal $g) => $g->initValue === null,
        );
        if ($bssGlobals !== []) {
            $this->emitter->bss();
            $this->emitter->align(8);
            foreach ($bssGlobals as $global) {
                if (!$global->isLocal) {
                    $this->emitter->global($global->name);
                }
                $this->emitter->type($global->name, 'object');
                $this->emitter->label($global->name);
                $this->emitter->zero($global->size);
                $this->emitter->size($global->name, $global->size . '');
                $this->emitter->blank();
            }
        }

        // ── .rodata — string constants ─────────────────────────────────────
        $hasStrings = $module->strings !== [];
        $hasVtables = $module->vtables !== [];

        if ($hasStrings || $hasVtables) {
            $this->emitter->rodata();
        }

        foreach ($module->strings as $lbl => $value) {
            $this->emitter->align(1);
            $this->emitter->label($lbl);
            $this->emitter->asciz($value);
            $this->emitter->blank();
        }

        foreach ($module->vtables as $lbl => $vtable) {
            $this->emitter->align(8);
            $this->emitter->label($lbl);
            foreach ($vtable['entries'] as $funcName) {
                $this->emitter->emit('.quad', $funcName);
            }
            $this->emitter->blank();
        }

        // ── .text ──────────────────────────────────────────────────────────
        $this->emitter->text();

        foreach ($module->functions as $func) {
            $this->generateFunction($func);
        }

        // ── .rodata — float constants (collected during .text pass) ────────
        if ($this->floatConsts !== []) {
            $this->emitter->rodata();
            foreach ($this->floatConsts as $lbl => $bits) {
                $this->emitter->align(8);
                $this->emitter->label($lbl);
                // Store as a 64-bit quad (double) or 32-bit long (float).
                // We store everything as 64-bit (double) quads for simplicity.
                $this->emitter->quad($bits);
                $this->emitter->blank();
            }
        }

        return $this->emitter->getOutput();
    }

    private function emitGlobalData(IRGlobal $global): void
    {
        if ($global->stringData !== null) {
            $str = $global->stringData;
            $written = 0;
            for ($i = 0, $len = strlen($str); $i < $len; $i++) {
                $this->emitter->byte(ord($str[$i]));
                $written++;
            }
            $padding = $global->size - $written;
            if ($padding > 0) {
                $this->emitter->zero($padding);
            }
            return;
        }

        $val = $global->initValue ?? '0';

        match ($global->size) {
            1       => $this->emitter->byte((int)$val),
            2       => $this->emitter->word((int)$val),
            4       => $this->emitter->long((int)$val),
            default => $this->emitter->quad(is_numeric($val) ? (int)$val : $val),
        };
    }

    private function generateFunction(IRFunction $func): void
    {
        $this->currentFunc   = $func;
        $this->epilogueLabel = '.L' . $func->name . '_epilogue';

        // ── Register allocation ────────────────────────────────────────────
        $this->allocation = $this->allocator->allocate($func);

        // ── Frame layout ───────────────────────────────────────────────────
        // Space needed: locals declared in IR + spill slots (8 bytes each).
        $spillBytes = $this->allocation->spillCount * 8;
        $localBytes = $func->stackSize;
        $raw        = $localBytes + $spillBytes;
        // 16-byte align: after push %rbp, rsp is 16-byte aligned.
        // sub $frameSize keeps it aligned if frameSize is a multiple of 16.
        // But callee-saved pushes (done after sub) can misalign by 8 if count is odd.
        $this->frameSize = ($raw + 15) & ~15;

        // ── Determine which callee-saved registers are used ────────────────
        $this->usedCalleeSaved = $this->collectUsedCalleeSaved();

        // Adjust frame for 16-byte alignment accounting for callee-saved pushes.
        if (count($this->usedCalleeSaved) % 2 !== 0) {
            $this->frameSize += 8;
        }

        // ── Function header ────────────────────────────────────────────────
        $this->emitter->blank();
        if (!$func->isLocal) {
            $this->emitter->global($func->name);
        }
        $this->emitter->type($func->name, 'function');
        $this->emitter->label($func->name);

        // ── Prologue ───────────────────────────────────────────────────────
        $this->emitter->comment('prologue');
        $this->emitter->push($this->emitter->reg('rbp'));
        $this->emitter->mov(
            $this->emitter->reg('rsp'),
            $this->emitter->reg('rbp'),
        );
        if ($this->frameSize > 0) {
            $this->emitter->sub(
                $this->emitter->imm($this->frameSize),
                $this->emitter->reg('rsp'),
            );
        }

        // Save callee-saved registers.
        foreach ($this->usedCalleeSaved as $reg) {
            $this->emitter->push($this->emitter->reg($reg));
        }

        // ── Copy ABI argument registers to stack homes ─────────────────────
        $this->emitter->comment('parameter homes');
        $this->emitParameterHomes($func);

        // ── Basic blocks ───────────────────────────────────────────────────
        foreach ($func->blocks as $block) {
            $this->generateBlock($block);
        }

        // ── Epilogue ───────────────────────────────────────────────────────
        $this->emitter->label($this->epilogueLabel);
        $this->emitter->comment('epilogue');
        foreach (array_reverse($this->usedCalleeSaved) as $reg) {
            $this->emitter->pop($this->emitter->reg($reg));
        }
        $this->emitter->mov(
            $this->emitter->reg('rbp'),
            $this->emitter->reg('rsp'),
        );
        $this->emitter->pop($this->emitter->reg('rbp'));
        $this->emitter->ret();
        $this->emitter->size($func->name, '.-' . $func->name);
        $this->emitter->blank();

        $this->currentFunc = null;
        $this->allocation  = null;
    }

    /**
     * Copy function arguments from ABI registers (rdi, rsi, …) or the caller's
     * stack frame into the callee's local stack homes so that the allocator can
     * treat every parameter as a simple stack slot.
     */
    private function emitParameterHomes(IRFunction $func): void
    {
        $intIdx   = 0;
        $floatIdx = 0;

        foreach ($func->params as $idx => $param) {
            $homeOffset = $this->paramHome($idx);
            $dst        = $this->emitter->mem('rbp', -$homeOffset);

            if ($param->isFloat) {
                $srcReg = $this->convention->getArgRegister($floatIdx, true);
                if ($srcReg !== null) {
                    $this->emitter->movsd(
                        $this->emitter->reg($srcReg),
                        $dst,
                    );
                } else {
                    // Stack argument: located at [rbp + 16 + stackArgIndex * 8].
                    $stackOff   = $this->convention->getArgStackOffset($idx, $intIdx, $floatIdx);
                    $srcMem     = $this->emitter->mem('rbp', 16 + $stackOff);
                    $tmpReg     = $this->emitter->reg('xmm15');
                    $this->emitter->movsd($srcMem, $tmpReg);
                    $this->emitter->movsd($tmpReg, $dst);
                }
                $floatIdx++;
            } else {
                $srcReg = $this->convention->getArgRegister($intIdx, false);
                if ($srcReg !== null) {
                    $this->emitter->mov(
                        $this->emitter->reg($srcReg),
                        $dst,
                    );
                } else {
                    $stackOff = $this->convention->getArgStackOffset($idx, $intIdx, $floatIdx);
                    $srcMem   = $this->emitter->mem('rbp', 16 + $stackOff);
                    $this->emitter->emit('movq', $srcMem, $this->emitter->reg('rax'));
                    $this->emitter->mov($this->emitter->reg('rax'), $dst);
                }
                $intIdx++;
            }
        }
    }

    /**
     * Returns the rbp-relative byte offset (positive) of a parameter's home
     * slot in the current frame.  Parameters are stored at the high end of the
     * frame (closest to rbp), so that param 0 is at -8(%rbp), param 1 at
     * -16(%rbp), etc.  This ensures the offset is always at least 8 and never
     * overlaps with the saved %rbp at 0(%rbp).
     */
    private function paramHome(int $paramIndex): int
    {
        // Parameters are homed starting from the very top of the frame
        // (immediately below %rbp).  Offset is always >= 8.
        return ($paramIndex + 1) * 8;
    }

    private function generateBlock(BasicBlock $block): void
    {
        $this->emitter->label($block->label);
        foreach ($block->instructions as $inst) {
            // Skip bare Label instructions — the block label itself covers them.
            if ($inst->opcode === OpCode::Label) {
                continue;
            }
            $this->generateInstruction($inst);
        }
    }

    private function generateInstruction(Instruction $inst): void
    {
        match ($inst->opcode) {
            // ── Arithmetic ─────────────────────────────────────────────────
            OpCode::Add   => $this->emitBinOp($inst, 'add'),
            OpCode::Sub   => $this->emitBinOp($inst, 'sub'),
            OpCode::Mul   => $this->emitBinOp($inst, 'imul'),
            OpCode::Div   => $this->emitDivMod($inst, quotient: true),
            OpCode::Mod   => $this->emitDivMod($inst, quotient: false),
            OpCode::Neg   => $this->emitUnaryOp($inst, 'neg'),

            // ── Float arithmetic ───────────────────────────────────────────
            OpCode::FAdd  => $this->emitFBinOp($inst, 'addsd'),
            OpCode::FSub  => $this->emitFBinOp($inst, 'subsd'),
            OpCode::FMul  => $this->emitFBinOp($inst, 'mulsd'),
            OpCode::FDiv  => $this->emitFBinOp($inst, 'divsd'),
            OpCode::FNeg  => $this->emitFNeg($inst),

            // ── Bitwise ────────────────────────────────────────────────────
            OpCode::And   => $this->emitBinOp($inst, 'and'),
            OpCode::Or    => $this->emitBinOp($inst, 'or'),
            OpCode::Xor   => $this->emitBinOp($inst, 'xor'),
            OpCode::Not   => $this->emitUnaryOp($inst, 'not'),
            OpCode::Shl   => $this->emitShift($inst, 'shl'),
            OpCode::Shr   => $this->emitShift($inst, 'sar'),   // arithmetic shift right

            // ── Integer comparison ─────────────────────────────────────────
            OpCode::CmpEq => $this->emitCmp($inst, 'sete'),
            OpCode::CmpNe => $this->emitCmp($inst, 'setne'),
            OpCode::CmpLt => $this->emitCmp($inst, 'setl'),
            OpCode::CmpLe => $this->emitCmp($inst, 'setle'),
            OpCode::CmpGt => $this->emitCmp($inst, 'setg'),
            OpCode::CmpGe => $this->emitCmp($inst, 'setge'),

            // ── Unsigned comparison ───────────────────────────────────────
            OpCode::UCmpLt => $this->emitCmp($inst, 'setb'),
            OpCode::UCmpLe => $this->emitCmp($inst, 'setbe'),
            OpCode::UCmpGt => $this->emitCmp($inst, 'seta'),
            OpCode::UCmpGe => $this->emitCmp($inst, 'setae'),

            // ── Float comparison ───────────────────────────────────────────
            OpCode::FCmpEq => $this->emitFCmp($inst, 'sete'),
            OpCode::FCmpNe => $this->emitFCmp($inst, 'setne'),
            OpCode::FCmpLt => $this->emitFCmp($inst, 'setb'),
            OpCode::FCmpLe => $this->emitFCmp($inst, 'setbe'),
            OpCode::FCmpGt => $this->emitFCmp($inst, 'seta'),
            OpCode::FCmpGe => $this->emitFCmp($inst, 'setae'),

            // ── Memory ────────────────────────────────────────────────────
            OpCode::Load         => $this->emitLoad($inst),
            OpCode::Store        => $this->emitStore($inst),
            OpCode::LoadAddr     => $this->emitLoadAddr($inst),
            OpCode::Alloca       => $this->emitAlloca($inst),
            OpCode::GetElementPtr => $this->emitGetElementPtr($inst),

            // ── Data movement ──────────────────────────────────────────────
            OpCode::LoadImm    => $this->emitLoadImm($inst),
            OpCode::LoadFloat  => $this->emitLoadFloat($inst),
            OpCode::LoadString => $this->emitLoadString($inst),
            OpCode::LoadGlobal => $this->emitLoadGlobal($inst),
            OpCode::StoreGlobal => $this->emitStoreGlobal($inst),
            OpCode::Move       => $this->emitMove($inst),

            // ── Control flow ───────────────────────────────────────────────
            OpCode::Jump      => $this->emitJump($inst),
            OpCode::JumpIf    => $this->emitJumpIf($inst, true),
            OpCode::JumpIfNot => $this->emitJumpIf($inst, false),

            // ── Function ───────────────────────────────────────────────────
            OpCode::Call    => $this->emitCall($inst),
            OpCode::Param   => $this->emitParam($inst),
            OpCode::Return_ => $this->emitReturn($inst),

            // ── Type conversion ────────────────────────────────────────────
            OpCode::IntToFloat => $this->emitIntToFloat($inst),
            OpCode::FloatToInt => $this->emitFloatToInt($inst),
            OpCode::SignExtend => $this->emitSignExtend($inst),
            OpCode::ZeroExtend => $this->emitZeroExtend($inst),
            OpCode::Truncate   => $this->emitTruncate($inst),
            OpCode::Bitcast    => $this->emitBitcast($inst),

            // ── Misc ───────────────────────────────────────────────────────
            OpCode::Nop   => $this->emitter->emit('nop'),
            OpCode::Label => null,   // already emitted as block label
            OpCode::Phi   => null,   // phi nodes are expected to have been lowered

            default => throw new CompileError(
                'X86Generator: unsupported opcode ' . $inst->opcode->value,
            ),
        };
    }

    // two-address form: mov src1 → dest_reg, then op src2, dest_reg
    private function emitBinOp(Instruction $inst, string $mnemonic): void
    {
        assert($inst->dest !== null && $inst->src1 !== null && $inst->src2 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src1    = $this->operandToAsm($inst->src1);
        $src2    = $this->operandToAsm($inst->src2);

        // Defensive: if either operand is XMM, move it to GPR first (XMM→GPR via movq).
        if ($this->isXmmReg($src1)) {
            $this->emitter->emit('movq', $src1, $this->emitter->reg('rax'));
            $src1 = $this->emitter->reg('rax');
        }
        if ($this->isXmmReg($src2)) {
            $this->emitter->emit('movq', $src2, $this->emitter->reg('rcx'));
            $src2 = $this->emitter->reg('rcx');
        }

        // Move src1 into a temporary GP register if dest is a memory slot.
        $destReg = $this->ensureGPReg($destLoc, 'rax');
        $this->emitter->mov($src1, $this->emitter->reg($destReg));

        // Large immediates (> 32-bit signed) must go through a register.
        if ($inst->src2->kind === \Cppc\IR\OperandKind::Immediate) {
            $immVal = $inst->src2->value;
            if ($immVal > 2147483647 || $immVal < -2147483648) {
                $tmpReg = $destReg === 'rax' ? 'rcx' : 'rax';
                $this->emitter->mov($src2, $this->emitter->reg($tmpReg));
                $src2 = $this->emitter->reg($tmpReg);
            }
        }

        $this->emitter->emit($mnemonic, $src2, $this->emitter->reg($destReg));
        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitDivMod(Instruction $inst, bool $quotient): void
    {
        assert($inst->dest !== null && $inst->src1 !== null && $inst->src2 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src1    = $this->operandToAsm($inst->src1);
        $src2    = $this->operandToAsm($inst->src2);

        // Dividend → rax.
        $this->emitter->mov($src1, $this->emitter->reg('rax'));
        $this->emitter->cqo();   // sign-extend rax → rdx:rax

        // Divisor must be in a register (idiv doesn't accept immediates).
        $divReg = 'rcx';
        if ($this->isMemRef($src2) || $this->isImmediate($src2)) {
            $this->emitter->mov($src2, $this->emitter->reg($divReg));
            $this->emitter->idiv($this->emitter->reg($divReg));
        } else {
            $this->emitter->idiv($src2);
        }

        $resultReg = $quotient ? 'rax' : 'rdx';
        $this->storeToDest($destLoc, $this->emitter->reg($resultReg));
    }

    private function emitUnaryOp(Instruction $inst, string $mnemonic): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        $this->emitter->mov($src, $this->emitter->reg($destReg));
        $this->emitter->emit($mnemonic, $this->emitter->reg($destReg));
        $this->storeIfSpill($destLoc, $destReg);
    }

    /**
     * Shift instructions: the shift count must be in cl or be an immediate.
     * Pattern: mov src1 → dest, mov src2 → rcx, shl/sar cl, dest.
     */
    private function emitShift(Instruction $inst, string $mnemonic): void
    {
        assert($inst->dest !== null && $inst->src1 !== null && $inst->src2 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src1    = $this->operandToAsm($inst->src1);
        $src2    = $this->operandToAsm($inst->src2);

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        $this->emitter->mov($src1, $this->emitter->reg($destReg));

        if ($this->isImmediate($src2)) {
            $this->emitter->emit($mnemonic, $src2, $this->emitter->reg($destReg));
        } else {
            $this->emitter->mov($src2, $this->emitter->reg('rcx'));
            $this->emitter->emit($mnemonic, $this->emitter->reg('cl'), $this->emitter->reg($destReg));
        }

        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitCmp(Instruction $inst, string $setCC): void
    {
        assert($inst->dest !== null && $inst->src1 !== null && $inst->src2 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src1    = $this->operandToAsm($inst->src1);
        $src2    = $this->operandToAsm($inst->src2);

        // If either operand is an XMM register, the IR incorrectly classified this
        // as integer — use float comparison (ucomisd) instead.
        if ($this->isXmmReg($src1) || $this->isXmmReg($src2)) {
            $xmm1 = $this->ensureXmm($src1, 'xmm8');
            $xmm2 = $this->ensureXmm($src2, 'xmm9');
            $this->emitter->ucomisd($xmm2, $xmm1);
            $this->emitter->emit($setCC, $this->emitter->reg('al'));
            $this->emitter->movzx($this->emitter->reg('al'), $this->emitter->reg('rax'));
            $this->storeToDest($destLoc, $this->emitter->reg('rax'));
            return;
        }

        // cmp requires at least one operand to be a register.
        $cmp1 = $src1;
        if ($this->isMemRef($src1) || $this->isImmediate($src1)) {
            $this->emitter->mov($src1, $this->emitter->reg('rax'));
            $cmp1 = $this->emitter->reg('rax');
        }

        // cmp with 64-bit register only accepts sign-extended imm32.
        // If the immediate exceeds the signed 32-bit range, load it into a register first.
        $cmp2 = $src2;
        if ($this->isImmediate($src2)) {
            $immVal = (int) substr($src2, 1);
            if ($immVal > 2147483647 || $immVal < -2147483648) {
                $scratch = ($cmp1 === $this->emitter->reg('rax'))
                    ? $this->emitter->reg('rcx')
                    : $this->emitter->reg('rax');
                $this->emitter->mov($src2, $scratch);
                $cmp2 = $scratch;
            }
        }

        $this->emitter->cmp($cmp2, $cmp1);
        $this->emitter->emit($setCC, $this->emitter->reg('al'));
        $this->emitter->movzx($this->emitter->reg('al'), $this->emitter->reg('rax'));
        $this->storeToDest($destLoc, $this->emitter->reg('rax'));
    }

    private function emitFBinOp(Instruction $inst, string $mnemonic): void
    {
        assert($inst->dest !== null && $inst->src1 !== null && $inst->src2 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src1    = $this->operandToAsm($inst->src1);
        $src2    = $this->operandToAsm($inst->src2);

        // Use xmm8 as scratch if dest is a spill slot.
        $destXmm = $this->isXmmReg($destLoc) ? $destLoc : 'xmm8';

        // Ensure src1 is in an XMM register (GPR→XMM needs movq, not movsd).
        $xmmSrc1 = $this->ensureXmm($src1, $destXmm);
        if ($xmmSrc1 !== $this->emitter->reg($destXmm)) {
            $this->emitter->movsd($xmmSrc1, $this->emitter->reg($destXmm));
        }

        // Ensure src2 is XMM-compatible (memory or XMM register, not GPR/immediate).
        $src2Asm = $src2;
        if ($this->isGPReg($src2) || str_starts_with($src2, '$')) {
            $src2Asm = $this->ensureXmm($src2, 'xmm9');
        }
        $this->emitter->emit($mnemonic, $src2Asm, $this->emitter->reg($destXmm));

        if ($destXmm === 'xmm8') {
            $this->emitter->movsd($this->emitter->reg($destXmm), $destLoc);
        }
    }

    /** Float negation via xorpd with a sign-bit mask constant in .rodata. */
    private function emitFNeg(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);

        // Emit a sign-mask constant into .rodata and load it.
        $maskLabel = $this->addFloatConst(PHP_INT_MIN, 'signmask'); // 0x8000000000000000 sign bit
        $destXmm   = $this->isXmmReg($destLoc) ? $destLoc : 'xmm8';

        // Ensure src is in an XMM register.
        $xmmSrc = $this->ensureXmm($src, $destXmm);
        if ($xmmSrc !== $this->emitter->reg($destXmm)) {
            $this->emitter->movsd($xmmSrc, $this->emitter->reg($destXmm));
        }
        // Load sign mask via movsd (8-byte aligned OK) into scratch register,
        // then xorpd register-register (avoids 16-byte alignment requirement
        // of xorpd with memory operand).
        $this->emitter->movsd($this->emitter->ripRel($maskLabel), $this->emitter->reg('xmm9'));
        $this->emitter->emit(
            'xorpd',
            $this->emitter->reg('xmm9'),
            $this->emitter->reg($destXmm),
        );

        if ($destXmm === 'xmm8') {
            $this->emitter->movsd($this->emitter->reg($destXmm), $destLoc);
        }
    }

    private function emitFCmp(Instruction $inst, string $setCC): void
    {
        assert($inst->dest !== null && $inst->src1 !== null && $inst->src2 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src1    = $this->operandToAsm($inst->src1);
        $src2    = $this->operandToAsm($inst->src2);

        // ucomisd requires both operands in XMM registers.
        $xmm1 = $this->ensureXmm($src1, 'xmm8');
        $xmm2 = $this->ensureXmm($src2, 'xmm9');

        $this->emitter->ucomisd($xmm2, $xmm1);
        $this->emitter->emit($setCC, $this->emitter->reg('al'));
        $this->emitter->movzx($this->emitter->reg('al'), $this->emitter->reg('rax'));
        $this->storeToDest($destLoc, $this->emitter->reg('rax'));
    }

    /** Move a value into an XMM register if it isn't already in one. */
    private function ensureXmm(string $loc, string $fallback): string
    {
        if ($this->isXmmReg($loc)) {
            return $loc;
        }
        $xmm = $this->emitter->reg($fallback);
        if (str_starts_with($loc, '$')) {
            // Immediate → XMM: can't movsd an immediate.
            $immVal = (int) substr($loc, 1);
            if ($immVal === 0) {
                $this->emitter->emit('xorpd', $xmm, $xmm);
            } else {
                // Integer immediate → convert to double via cvtsi2sd.
                $this->emitter->mov($loc, $this->emitter->reg('rax'));
                $this->emitter->cvtsi2sd($this->emitter->reg('rax'), $xmm);
            }
        } elseif ($this->isGPReg($loc)) {
            // GPR → XMM: bit-pattern copy (value may be a float in a GPR due to spill).
            $this->emitter->mov($loc, $xmm);
        } else {
            // Memory → XMM: movsd works fine.
            $this->emitter->movsd($loc, $xmm);
        }
        return $xmm;
    }

    private function emitLoad(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc  = $this->resolveOperandLoc($inst->dest);
        $addrOp   = $inst->src1;
        $size     = $inst->dest->size;
        $destIsFloat = $this->isXmmReg($destLoc);

        // If source is a stack slot or param, load directly from memory.
        if ($addrOp->kind === OperandKind::StackSlot || $addrOp->kind === OperandKind::Param) {
            $memSrc = $this->operandToAsm($addrOp);
            if ($destIsFloat) {
                $this->emitter->movsd($memSrc, $this->emitter->reg($destLoc));
            } else {
                $tmpGP = $this->ensureGPReg($destLoc, 'rcx');
                $this->emitter->mov($memSrc, $this->emitter->reg($tmpGP));
                $this->storeIfSpill($destLoc, $tmpGP);
            }
            return;
        }

        // Otherwise, address is in a register — dereference it.
        $addrSrc = $this->operandToAsm($addrOp);
        $addrReg = 'rax';
        if ($this->isGPReg($addrSrc)) {
            $addrReg = ltrim($addrSrc, '%');
        } else {
            $this->emitter->mov($addrSrc, $this->emitter->reg($addrReg));
        }

        if ($destIsFloat) {
            $this->emitter->movsd($this->emitter->mem($addrReg), $this->emitter->reg($destLoc));
        } else {
            $tmpGP = $this->ensureGPReg($destLoc, 'rcx');
            if ($size === 1) {
                $this->emitter->emit('movzbl', $this->emitter->mem($addrReg), $this->emitter->reg($this->getRegForSize($tmpGP, 4)));
            } elseif ($size === 2) {
                $this->emitter->emit('movzwl', $this->emitter->mem($addrReg), $this->emitter->reg($this->getRegForSize($tmpGP, 4)));
            } else {
                $suffix = AsmEmitter::sizeSuffix($size);
                $this->emitter->emit('mov' . $suffix, $this->emitter->mem($addrReg), $this->emitter->reg($this->getRegForSize($tmpGP, $size)));
            }
            $this->storeIfSpill($destLoc, $tmpGP);
        }
    }

    private function emitStore(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $addrOp   = $inst->dest;
        $valueOp  = $inst->src1;
        $valueSrc = $this->operandToAsm($valueOp);

        // If the address is a stack slot or param, we can store directly to memory.
        if ($addrOp->kind === OperandKind::StackSlot || $addrOp->kind === OperandKind::Param) {
            $memDst = $this->operandToAsm($addrOp);
            if ($this->isXmmReg($valueSrc)) {
                $this->emitter->movsd($valueSrc, $memDst);
            } elseif ($valueOp->kind === OperandKind::Immediate) {
                $size = $addrOp->size > 0 ? $addrOp->size : 8;
                $immVal = (int)$valueOp->value;
                if ($size >= 8 && ($immVal > 2147483647 || $immVal < -2147483648)) {
                    $this->emitter->mov($valueSrc, $this->emitter->reg('rax'));
                    $this->emitter->mov($this->emitter->reg('rax'), $memDst);
                } else {
                    $suffix = AsmEmitter::sizeSuffix($size);
                    $this->emitter->emit('mov' . $suffix, $valueSrc, $memDst);
                }
            } else {
                $valReg = 'rax';
                if ($this->isGPReg($valueSrc)) {
                    $valReg = ltrim($valueSrc, '%');
                } else {
                    $this->emitter->mov($valueSrc, $this->emitter->reg($valReg));
                }
                $this->emitter->mov($this->emitter->reg($valReg), $memDst);
            }
            return;
        }

        // Otherwise, the address is in a register (pointer dereference).
        $addrSrc = $this->operandToAsm($addrOp);
        $addrReg = 'rcx';
        if ($this->isGPReg($addrSrc)) {
            $addrReg = ltrim($addrSrc, '%');
        } else {
            $this->emitter->mov($addrSrc, $this->emitter->reg($addrReg));
        }

        // Memory access size: from src2 hint, then value operand size, fallback to 8.
        $memSize = 8;
        if ($inst->src2 !== null && $inst->src2->kind === OperandKind::Immediate) {
            $memSize = (int)$inst->src2->value;
        } elseif ($valueOp->size > 0 && $valueOp->size < 8) {
            $memSize = $valueOp->size;
        }

        if ($this->isXmmReg($valueSrc)) {
            $this->emitter->movsd($valueSrc, $this->emitter->mem($addrReg));
        } else {
            $valReg = 'rax';
            if ($this->isGPReg($valueSrc)) {
                $valReg = ltrim($valueSrc, '%');
            } else {
                $this->emitter->mov($valueSrc, $this->emitter->reg($valReg));
            }
            $suffix = AsmEmitter::sizeSuffix($memSize);
            $this->emitter->emit(
                'mov' . $suffix,
                $this->emitter->reg($this->getRegForSize($valReg, $memSize)),
                $this->emitter->mem($addrReg),
            );
        }
    }

    private function emitLoadAddr(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);

        // Global symbol: load address via RIP-relative LEA.
        if ($inst->src1->kind === OperandKind::Global) {
            $destReg = $this->ensureGPReg($destLoc, 'rax');
            $this->emitter->lea(
                $this->emitter->ripRel((string)$inst->src1->value),
                $this->emitter->reg($destReg),
            );
            $this->storeIfSpill($destLoc, $destReg);
            return;
        }

        // src1 is either a StackSlot operand or holds the local variable name as a string.
        $offset = match ($inst->src1->kind) {
            OperandKind::StackSlot => -(int)$inst->src1->value,
            OperandKind::Immediate => -(int)$inst->src1->value,
            default => $this->currentFunc?->locals[(string)$inst->src1->value]
                ? -($this->currentFunc->locals[(string)$inst->src1->value])
                : 0,
        };

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        $this->emitter->lea($this->emitter->mem('rbp', $offset), $this->emitter->reg($destReg));
        $this->storeIfSpill($destLoc, $destReg);
    }

    /**
     * Alloca is fully handled in the prologue frame layout; at the instruction
     * site we just compute the address of the allocated slot.
     */
    private function emitAlloca(Instruction $inst): void
    {
        // In our IR model, alloca declares stack space that was already reserved
        // in the prologue via IRFunction::stackSize. Nothing to emit.
    }

    private function emitGetElementPtr(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $baseSrc = $this->operandToAsm($inst->src1);
        // StackSlot and Param operands represent variable addresses on the stack.
        // We need lea (address-of) rather than mov (load value).
        $baseIsAddr = $inst->src1->kind === OperandKind::StackSlot
            || $inst->src1->kind === OperandKind::Param;

        // src2 holds the byte offset (may be a constant or vreg).
        $destReg = $this->ensureGPReg($destLoc, 'rax');

        if ($inst->src2 === null) {
            // Simple load of base address.
            if ($baseIsAddr) {
                $this->emitter->lea($baseSrc, $this->emitter->reg($destReg));
            } else {
                $this->emitter->mov($baseSrc, $this->emitter->reg($destReg));
            }
        } elseif ($inst->src2->kind === OperandKind::Immediate) {
            $offset = (int)$inst->src2->value;
            // For stack slot bases, compute address directly: lea (rbp_offset + member_offset)(%rbp)
            if ($baseIsAddr && $inst->src1->kind === OperandKind::StackSlot) {
                $stackOff = -(int)$inst->src1->value;
                $this->emitter->lea(
                    $this->emitter->mem('rbp', $stackOff + $offset),
                    $this->emitter->reg($destReg),
                );
            } elseif ($baseIsAddr && $inst->src1->kind === OperandKind::Param) {
                $paramOff = -$this->paramHome((int)$inst->src1->value);
                $this->emitter->lea(
                    $this->emitter->mem('rbp', $paramOff + $offset),
                    $this->emitter->reg($destReg),
                );
            } else {
                // lea offset(%base), destReg
                $baseReg = 'rcx';
                if (!$this->isGPReg($baseSrc)) {
                    $this->emitter->mov($baseSrc, $this->emitter->reg($baseReg));
                } else {
                    $baseReg = ltrim($baseSrc, '%');
                }
                $this->emitter->lea(
                    $this->emitter->mem($baseReg, $offset),
                    $this->emitter->reg($destReg),
                );
            }
        } else {
            // Dynamic index: base + index * element_size.
            // element_size is in extra[0] if present, otherwise 1.
            $stride  = isset($inst->extra[0]) ? (int)$inst->extra[0]->value : 1;
            $idxSrc  = $this->operandToAsm($inst->src2);

            $baseReg = 'rcx';
            if ($baseIsAddr) {
                $this->emitter->lea($baseSrc, $this->emitter->reg($baseReg));
            } elseif (!$this->isGPReg($baseSrc)) {
                $this->emitter->mov($baseSrc, $this->emitter->reg($baseReg));
            } else {
                $baseReg = ltrim($baseSrc, '%');
            }

            $idxReg = 'rdx';
            if (!$this->isGPReg($idxSrc)) {
                $this->emitter->mov($idxSrc, $this->emitter->reg($idxReg));
            } else {
                $idxReg = ltrim($idxSrc, '%');
            }

            // lea (%base,%idx,stride), dest
            if (in_array($stride, [1, 2, 4, 8], true)) {
                $this->emitter->lea(
                    $this->emitter->memIdx($baseReg, $idxReg, $stride),
                    $this->emitter->reg($destReg),
                );
            } else {
                // Non-power-of-2 stride: multiply manually.
                $this->emitter->emit('imul', $this->emitter->imm($stride), $this->emitter->reg($idxReg));
                $this->emitter->add($this->emitter->reg($baseReg), $this->emitter->reg($idxReg));
                $this->emitter->mov($this->emitter->reg($idxReg), $this->emitter->reg($destReg));
            }
        }

        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitLoadImm(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $val     = (int)$inst->src1->value;

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        if ($val === 0) {
            // xor is smaller than mov $0.
            $this->emitter->xor_(
                $this->emitter->reg($destReg),
                $this->emitter->reg($destReg),
            );
        } else {
            $this->emitter->mov($this->emitter->imm($val), $this->emitter->reg($destReg));
        }
        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitLoadFloat(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $fval    = (float)$inst->src1->value;
        $bits    = $this->doubleToInt($fval);
        $lbl     = $this->addFloatConst($bits, 'fc');

        $destXmm = $this->isXmmReg($destLoc) ? $destLoc : 'xmm8';
        $this->emitter->movsd($this->emitter->ripRel($lbl), $this->emitter->reg($destXmm));

        if ($destXmm === 'xmm8') {
            $this->emitter->movsd($this->emitter->reg($destXmm), $destLoc);
        }
    }

    private function emitLoadString(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $lbl     = (string)$inst->src1->value;

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        $this->emitter->lea($this->emitter->ripRel($lbl), $this->emitter->reg($destReg));
        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitLoadGlobal(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $name    = (string)$inst->src1->value;
        $size    = $inst->dest->size ?: 8;

        if ($this->isXmmLoc($destLoc)) {
            $destXmm = $this->isXmmReg($destLoc) ? $destLoc : 'xmm8';
            $this->emitter->movsd($this->emitter->ripRel($name), $this->emitter->reg($destXmm));
            if ($destXmm === 'xmm8') {
                $this->emitter->movsd($this->emitter->reg('xmm8'), $destLoc);
            }
            return;
        }

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        $sizedReg = $this->getRegForSize($destReg, $size);
        $suffix   = AsmEmitter::sizeSuffix($size);
        $this->emitter->emit('mov' . $suffix, $this->emitter->ripRel($name), $this->emitter->reg($sizedReg));
        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitStoreGlobal(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $name    = (string)$inst->dest->value;
        $valSrc  = $this->operandToAsm($inst->src1);
        // Use explicit size hint if present, otherwise fall back to value size.
        $size    = ($inst->src2 !== null && $inst->src2->kind === OperandKind::Immediate)
            ? (int)$inst->src2->value
            : ($inst->src1->size ?: 8);

        if ($this->isXmmReg($valSrc)) {
            $this->emitter->movsd($valSrc, $this->emitter->ripRel($name));
            return;
        }

        $valReg = 'rax';
        if (!$this->isGPReg($valSrc)) {
            $this->emitter->mov($valSrc, $this->emitter->reg($valReg));
        } else {
            $valReg = ltrim($valSrc, '%');
        }
        $sizedReg = $this->getRegForSize($valReg, $size);
        $suffix   = AsmEmitter::sizeSuffix($size);
        $this->emitter->emit('mov' . $suffix, $this->emitter->reg($sizedReg), $this->emitter->ripRel($name));
    }

    private function emitMove(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);

        $srcIsXmm  = $this->isXmmReg($src);
        $destIsXmm = $this->isXmmReg($destLoc);
        $srcIsGPR  = $this->isGPReg($src);
        $destIsGPR = $this->isGPReg($destLoc);

        if ($srcIsXmm && $destIsXmm) {
            // XMM → XMM
            if ($src !== $this->emitter->reg($destLoc)) {
                $this->emitter->movsd($src, $this->emitter->reg($destLoc));
            }
        } elseif ($srcIsXmm && $destIsGPR) {
            // XMM → GPR: movq
            $this->emitter->emit('movq', $src, $this->emitter->reg($destLoc));
        } elseif ($srcIsXmm) {
            // XMM → memory: movsd
            $this->emitter->movsd($src, $destLoc);
        } elseif ($destIsXmm) {
            // * → XMM: use ensureXmm
            $xmm = $this->ensureXmm($src, ltrim($destLoc, '%'));
            if ($xmm !== $this->emitter->reg($destLoc)) {
                $this->emitter->movsd($xmm, $this->emitter->reg($destLoc));
            }
        } else {
            // GPR/memory moves
            $destReg = $this->ensureGPReg($destLoc, 'rax');
            $this->emitter->mov($src, $this->emitter->reg($destReg));
            $this->storeIfSpill($destLoc, $destReg);
        }
    }

    private function emitJump(Instruction $inst): void
    {
        assert($inst->src1 !== null);
        $target = $this->labelName($inst->src1);
        $this->emitter->jmp($target);
    }

    private function emitJumpIf(Instruction $inst, bool $condition): void
    {
        assert($inst->src1 !== null && $inst->src2 !== null);

        $cond   = $this->operandToAsm($inst->src1);
        $target = $this->labelName($inst->src2);

        // test reg, reg sets ZF when reg == 0.
        $condReg = 'rax';
        if (!$this->isGPReg($cond)) {
            $this->emitter->mov($cond, $this->emitter->reg($condReg));
        } else {
            $condReg = ltrim($cond, '%');
        }
        $this->emitter->test(
            $this->emitter->reg($condReg),
            $this->emitter->reg($condReg),
        );

        if ($condition) {
            $this->emitter->jne($target);   // jump if non-zero (true)
        } else {
            $this->emitter->je($target);    // jump if zero (false)
        }
    }

    private function emitCall(Instruction $inst): void
    {
        assert($inst->src1 !== null);

        $funcName = (string)$inst->src1->value;

        // ── Save caller-saved registers that are live across this call ─────
        // For simplicity we save all caller-saved registers that are currently
        // assigned to live vregs in the allocation result.
        // Exclude the destination register — the call result will overwrite it.
        $excludeRegs = ['rax']; // rax always holds the return value
        if ($inst->dest !== null) {
            $destLoc = $this->resolveOperandLoc($inst->dest);
            if ($this->isGPReg($destLoc)) {
                $excludeRegs[] = ltrim($destLoc, '%');
            }
        }
        $savedCallerSaved = $this->saveCallerSaved($excludeRegs);

        // ── Marshal arguments ──────────────────────────────────────────────
        $intIdx   = 0;
        $floatIdx = 0;
        $stackArgs = [];

        foreach ($inst->extra as $argOp) {
            $argSrc    = $this->operandToAsm($argOp);
            $isFloat   = $argOp->kind === OperandKind::VirtualReg
                ? $this->isXmmLoc($this->resolveOperandLoc($argOp))
                : false;

            $destReg = $this->convention->getArgRegister(
                $isFloat ? $floatIdx : $intIdx,
                $isFloat,
            );

            if ($destReg !== null) {
                if ($isFloat) {
                    $this->emitter->movsd($argSrc, $this->emitter->reg($destReg));
                    $floatIdx++;
                } else {
                    $this->emitter->mov($argSrc, $this->emitter->reg($destReg));
                    $intIdx++;
                }
            } else {
                // Stack argument — push in reverse order below.
                $stackArgs[] = [$argSrc, $isFloat];
                if ($isFloat) {
                    $floatIdx++;
                } else {
                    $intIdx++;
                }
            }
        }

        // Push stack arguments in reverse order.
        foreach (array_reverse($stackArgs) as [$argSrc, $isFloat]) {
            if ($isFloat) {
                // Move to a GP register via rax for pushing.
                $this->emitter->movsd($argSrc, $this->emitter->reg('xmm8'));
                $this->emitter->emit('sub', $this->emitter->imm(8), $this->emitter->reg('rsp'));
                $this->emitter->movsd($this->emitter->reg('xmm8'), $this->emitter->mem('rsp'));
            } else {
                $pushSrc = $this->isGPReg($argSrc) ? $argSrc : null;
                if ($pushSrc === null) {
                    $this->emitter->mov($argSrc, $this->emitter->reg('rax'));
                    $pushSrc = $this->emitter->reg('rax');
                }
                $this->emitter->push($pushSrc);
            }
        }

        // Align stack to 16 bytes before the call. Must account for all pushes
        // since the aligned base state (caller-saved saves + stack args).
        $stackArgCount = count($stackArgs);
        $totalPushes   = count($savedCallerSaved) + $stackArgCount;
        $needsPad      = ($totalPushes % 2) !== 0;
        if ($needsPad) {
            $this->emitter->sub($this->emitter->imm(8), $this->emitter->reg('rsp'));
        }

        // ── Actual call ────────────────────────────────────────────────────
        // SysV ABI: variadic functions require %al = number of SSE register args.
        // For non-variadic functions this is harmless but unnecessary.
        if ($inst->isVariadicCall || $floatIdx > 0) {
            if ($floatIdx > 0) {
                $this->emitter->mov($this->emitter->imm($floatIdx), $this->emitter->reg('al'));
            } else {
                // Variadic with no float args: zero out %eax (clears %al).
                $this->emitter->emit('xorl', $this->emitter->reg('eax'), $this->emitter->reg('eax'));
            }
        }
        if ($inst->src1->kind === OperandKind::VirtualReg) {
            // Indirect call through a function pointer in a register.
            $fnPtrSrc = $this->operandToAsm($inst->src1);
            $fnReg = 'rax';
            if ($this->isGPReg($fnPtrSrc)) {
                $fnReg = ltrim($fnPtrSrc, '%');
            } else {
                $this->emitter->mov($fnPtrSrc, $this->emitter->reg($fnReg));
            }
            $this->emitter->emit('call', '*' . $this->emitter->reg($fnReg));
        } else {
            $callTarget = $funcName;
            // In PIC mode, external function calls go through PLT
            if ($this->picMode && !$this->isLocalFunction($funcName)) {
                $callTarget = $funcName . '@PLT';
            }
            $this->emitter->call($callTarget);
        }

        // ── Clean up stack arguments ───────────────────────────────────────
        $totalPop = ($stackArgCount + ($needsPad ? 1 : 0)) * 8;
        if ($totalPop > 0) {
            $this->emitter->add($this->emitter->imm($totalPop), $this->emitter->reg('rsp'));
        }

        // ── Capture return value ───────────────────────────────────────────
        if ($inst->dest !== null) {
            $destLoc     = $this->resolveOperandLoc($inst->dest);
            $isFloatRet  = $inst->dest->size === 0; // convention: size 0 = float (caller sets)
            // Actually check if dest is an xmm location.
            $isFloatRet  = $this->isXmmLoc($destLoc);

            if ($isFloatRet) {
                // Result is in xmm0.
                if ($this->isXmmReg($destLoc)) {
                    if (ltrim($destLoc, '%') !== 'xmm0') {
                        $this->emitter->movsd(
                            $this->emitter->reg('xmm0'),
                            $this->emitter->reg(ltrim($destLoc, '%')),
                        );
                    }
                } else {
                    $this->emitter->movsd($this->emitter->reg('xmm0'), $destLoc);
                }
            } else {
                $this->storeToDest($destLoc, $this->emitter->reg('rax'));
            }
        }

        // ── Restore caller-saved registers ─────────────────────────────────
        $this->restoreCallerSaved($savedCallerSaved);
    }

    private function emitParam(Instruction $inst): void
    {
        // This is a no-op in our scheme — args are handled inline in emitCall.
        // A Param instruction outside of a Call context would need separate handling.
    }

    private function emitReturn(Instruction $inst): void
    {
        if ($inst->src1 !== null) {
            $src = $this->operandToAsm($inst->src1);

            if ($this->currentFunc?->returnIsFloat) {
                // Float return: move to xmm0.
                if ($this->isXmmReg($src)) {
                    if (ltrim($src, '%') !== 'xmm0') {
                        $this->emitter->movsd($src, $this->emitter->reg('xmm0'));
                    }
                } elseif ($this->isGPReg($src)) {
                    // GPR value returning as float — movq gpr → xmm.
                    $this->emitter->emit('movq', $src, $this->emitter->reg('xmm0'));
                } elseif (str_starts_with($src, '$')) {
                    // Immediate (e.g. $0 for float 0.0) — use ensureXmm to load.
                    $this->ensureXmm($src, 'xmm0');
                } else {
                    $this->emitter->movsd($src, $this->emitter->reg('xmm0'));
                }
            } else {
                // Integer return: move to rax.
                if ($this->isXmmReg($src)) {
                    // Float value returning as int — movq xmm → gpr.
                    $this->emitter->emit('movq', $src, $this->emitter->reg('rax'));
                } elseif ($this->isGPReg($src)) {
                    if (ltrim($src, '%') !== 'rax') {
                        $this->emitter->mov($src, $this->emitter->reg('rax'));
                    }
                } else {
                    $this->emitter->mov($src, $this->emitter->reg('rax'));
                }
            }
        }

        $this->emitter->jmp($this->epilogueLabel);
    }

    private function emitIntToFloat(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);

        // If the source is already in an XMM register, treat as float→float move.
        if ($this->isXmmReg($src)) {
            if ($this->isXmmReg($destLoc)) {
                if ($src !== $destLoc) {
                    $this->emitter->movsd($src, $destLoc);
                }
            } else {
                $this->emitter->movsd($src, $destLoc);
            }
            return;
        }

        $srcReg = 'rax';
        if (!$this->isGPReg($src)) {
            $this->emitter->mov($src, $this->emitter->reg($srcReg));
        } else {
            $srcReg = ltrim($src, '%');
        }

        $destXmm = $this->isXmmReg($destLoc) ? ltrim($destLoc, '%') : 'xmm8';
        $this->emitter->cvtsi2sd(
            $this->emitter->reg($srcReg),
            $this->emitter->reg($destXmm),
        );

        if ($destXmm === 'xmm8') {
            $this->emitter->movsd($this->emitter->reg($destXmm), $destLoc);
        }
    }

    /** Truncates toward zero — C standard (int) cast semantics. */
    private function emitFloatToInt(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);

        $srcXmm = 'xmm8';
        if ($this->isXmmReg($src)) {
            $srcXmm = ltrim($src, '%');
        } else {
            $this->emitter->movsd($src, $this->emitter->reg($srcXmm));
        }

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        $this->emitter->cvttsd2si(
            $this->emitter->reg($srcXmm),
            $this->emitter->reg($destReg),
        );
        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitSignExtend(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);
        $srcSize = $inst->src1->size;

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        // Always move source to full 64-bit %rax to avoid register size mismatch.
        if ($this->isXmmReg($src)) {
            $this->emitter->emit('movq', $src, $this->emitter->reg('rax'));
        } else {
            $this->emitter->mov($src, $this->emitter->reg('rax'));
        }
        $srcSubReg = $this->getRegForSize('rax', $srcSize);
        $this->emitter->movsx(
            $this->emitter->reg($srcSubReg),
            $this->emitter->reg($destReg),
        );
        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitZeroExtend(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);
        $srcSize = $inst->src1->size;

        $destReg = $this->ensureGPReg($destLoc, 'rax');
        // Always move source to full 64-bit %rax to avoid register size mismatch.
        if ($this->isXmmReg($src)) {
            $this->emitter->emit('movq', $src, $this->emitter->reg('rax'));
        } else {
            $this->emitter->mov($src, $this->emitter->reg('rax'));
        }
        $srcSubReg = $this->getRegForSize('rax', $srcSize);
        $this->emitter->movzx(
            $this->emitter->reg($srcSubReg),
            $this->emitter->reg($destReg),
        );
        $this->storeIfSpill($destLoc, $destReg);
    }

    private function emitTruncate(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);
        $dstSize = $inst->dest->size;

        $destReg    = $this->ensureGPReg($destLoc, 'rax');
        $destSubReg = $this->getRegForSize($destReg, $dstSize);
        // Always move source to full 64-bit %rax to avoid register size mismatch.
        if ($this->isXmmReg($src)) {
            $this->emitter->emit('movq', $src, $this->emitter->reg('rax'));
        } else {
            $this->emitter->mov($src, $this->emitter->reg('rax'));
        }
        // Lower bits of %rax contain the truncated result.
        if ($destSubReg !== $this->getRegForSize('rax', $dstSize)) {
            $this->emitter->mov(
                $this->emitter->reg($this->getRegForSize('rax', $dstSize)),
                $this->emitter->reg($destSubReg),
            );
        }
        $this->storeIfSpill($destLoc, $destReg);
    }

    /**
     * Bitcast: reinterpret bits without conversion.
     * For int→float or float→int reinterpretation we use movq.
     */
    private function emitBitcast(Instruction $inst): void
    {
        assert($inst->dest !== null && $inst->src1 !== null);

        $destLoc = $this->resolveOperandLoc($inst->dest);
        $src     = $this->operandToAsm($inst->src1);

        $srcIsXmm  = $this->isXmmReg($src);
        $dstIsXmm  = $this->isXmmLoc($destLoc);

        if ($srcIsXmm && !$dstIsXmm) {
            // float bits → int register: movq xmm, gp
            $destReg = $this->ensureGPReg($destLoc, 'rax');
            $this->emitter->emit('movq', $src, $this->emitter->reg($destReg));
            $this->storeIfSpill($destLoc, $destReg);
        } elseif (!$srcIsXmm && $dstIsXmm) {
            // int bits → float register: movq gp, xmm
            $srcReg = 'rax';
            if (!$this->isGPReg($src)) {
                $this->emitter->mov($src, $this->emitter->reg($srcReg));
            } else {
                $srcReg = ltrim($src, '%');
            }
            $destXmm = $this->isXmmReg($destLoc) ? ltrim($destLoc, '%') : 'xmm8';
            $this->emitter->emit('movq', $this->emitter->reg($srcReg), $this->emitter->reg($destXmm));
            if ($destXmm === 'xmm8') {
                $this->emitter->movsd($this->emitter->reg($destXmm), $destLoc);
            }
        } else {
            // Same class: plain move.
            $this->emitMove($inst);
        }
    }

    private function operandToAsm(Operand $op): string
    {
        return match ($op->kind) {
            OperandKind::VirtualReg => $this->vregToAsm((int)$op->value),
            OperandKind::Immediate  => $this->emitter->imm((int)$op->value),
            OperandKind::FloatImm   => $this->lowerFloatImm($op),
            OperandKind::Label      => (string)$op->value,
            OperandKind::Global     => $this->emitter->ripRel((string)$op->value),
            OperandKind::String     => $this->emitter->ripRel((string)$op->value),
            OperandKind::FuncName   => '$' . (string)$op->value,
            OperandKind::StackSlot  => $this->emitter->mem('rbp', -(int)$op->value),
            OperandKind::Param      => $this->emitter->mem('rbp', -$this->paramHome((int)$op->value)),
        };
    }

    /** Defensively lower a FloatImm operand into an XMM register on-the-fly. */
    private function lowerFloatImm(Operand $op): string
    {
        $fval = (float)$op->value;
        $bits = $this->doubleToInt($fval);
        $lbl  = $this->addFloatConst($bits, 'fc');
        $xmm  = $this->emitter->reg('xmm9');
        $this->emitter->movsd($this->emitter->ripRel($lbl), $xmm);
        return $xmm;
    }

    private function vregToAsm(int $vregId): string
    {
        if ($this->allocation === null) {
            throw new CompileError("Register allocation result not available");
        }
        $loc = $this->allocation->getLocation($vregId);

        // If the allocator returns a register name (no %, no []), format it.
        if (!str_starts_with($loc, '%') && !str_starts_with($loc, '[') && !str_contains($loc, '(')) {
            return $this->emitter->reg($loc);
        }

        // spill slot like [rbp-24] → convert to AT&T mem syntax.
        if (str_starts_with($loc, '[rbp-')) {
            $offset = -(int)substr($loc, 5, -1);
            return $this->emitter->mem('rbp', $offset);
        }

        return $loc;
    }

    private function resolveOperandLoc(Operand $op): string
    {
        if ($op->kind !== OperandKind::VirtualReg) {
            return $this->operandToAsm($op);
        }

        if ($this->allocation === null) {
            throw new CompileError("Register allocation result not available");
        }

        $loc = $this->allocation->getLocation((int)$op->value);

        // spill slot → AT&T memory string.
        if (str_starts_with($loc, '[rbp-')) {
            $offset = -(int)substr($loc, 5, -1);
            return $this->emitter->mem('rbp', $offset);
        }

        // Physical register name → AT&T format with % prefix.
        return $this->emitter->reg($loc);
    }

    /** Returns $loc if it is a GP register, otherwise returns the $fallback scratch register. */
    private function ensureGPReg(string $loc, string $fallback): string
    {
        // $loc may be a bare register name like 'rbx' or a formatted string like '%rax'
        $name = ltrim($loc, '%');
        if (isset(self::REG_SIZES[$name])) {
            return $name;
        }
        return $fallback;
    }

    /** Writes $reg back to $destLoc only when $destLoc is a spill slot (not a physical register). */
    private function storeIfSpill(string $destLoc, string $reg): void
    {
        $name = ltrim($destLoc, '%');
        if (!isset(self::REG_SIZES[$name])) {
            // destLoc is a memory reference — store the scratch reg to it.
            $this->emitter->mov($this->emitter->reg($reg), $destLoc);
        }
    }

    private function storeToDest(string $destLoc, string $src): void
    {
        $destName = ltrim($destLoc, '%');
        $srcIsXmm = $this->isXmmReg($src);
        $destIsXmm = $this->isXmmReg($destLoc);

        if ($srcIsXmm && $destIsXmm) {
            if (ltrim($src, '%') !== ltrim($destLoc, '%')) {
                $this->emitter->movsd($src, $destLoc);
            }
        } elseif ($srcIsXmm && isset(self::REG_SIZES[$destName])) {
            // XMM → GPR: use movq
            $this->emitter->emit('movq', $src, $this->emitter->reg($destName));
        } elseif ($srcIsXmm) {
            // XMM → memory
            $this->emitter->movsd($src, $destLoc);
        } elseif ($destIsXmm) {
            // GPR/mem → XMM
            $this->ensureXmm($src, ltrim($destLoc, '%'));
        } elseif (isset(self::REG_SIZES[$destName])) {
            if (ltrim($src, '%') !== $destName) {
                $this->emitter->mov($src, $this->emitter->reg($destName));
            }
        } else {
            $this->emitter->mov($src, $destLoc);
        }
    }

    private function isGPReg(string $loc): bool
    {
        $name = ltrim($loc, '%');
        return isset(self::REG_SIZES[$name]);
    }

    /**
     * Check if a function name is defined locally in this module.
     */
    private function isLocalFunction(string $name): bool
    {
        return isset($this->localFunctions[$name]);
    }

    private function isXmmReg(string $loc): bool
    {
        $name = ltrim($loc, '%');
        return str_starts_with($name, 'xmm');
    }

    private function isXmmLoc(string $loc): bool
    {
        return $this->isXmmReg($loc);
    }

    private function isMemRef(string $loc): bool
    {
        return str_contains($loc, '(') || str_starts_with($loc, '[');
    }

    private function isImmediate(string $loc): bool
    {
        return str_starts_with($loc, '$');
    }

    /**
     * Returns the sub-register name for $reg at the given byte size.
     * e.g. getRegForSize('rax', 4) → 'eax', getRegForSize('rax', 1) → 'al'
     */
    public function getRegForSize(string $reg, int $size): string
    {
        $baseName = ltrim($reg, '%');
        if (!isset(self::REG_SIZES[$baseName])) {
            return $baseName;   // XMM or unknown — return as-is.
        }

        $idx = match (true) {
            $size >= 8 => 0,
            $size >= 4 => 1,
            $size >= 2 => 2,
            default    => 3,
        };

        return self::REG_SIZES[$baseName][$idx];
    }

    /**
     * Pushes all caller-saved registers that are currently mapped to live vregs.
     * Returns the list so restoreCallerSaved() can pop them in reverse order.
     *
     * @return string[]
     */
    /** @param string[] $exclude Registers to skip (e.g., call destination) */
    private function saveCallerSaved(array $exclude = []): array
    {
        $callerSaved = CallingConvention::CALLER_SAVED;
        $saved       = [];

        if ($this->allocation === null) {
            return $saved;
        }

        foreach ($this->allocation->regMap as $vregId => $physReg) {
            if (in_array($physReg, $callerSaved, true)
                && !in_array($physReg, $saved, true)
                && !in_array($physReg, $exclude, true)) {
                $this->emitter->push($this->emitter->reg($physReg));
                $saved[] = $physReg;
            }
        }

        return $saved;
    }

    /** @param string[] $saved */
    private function restoreCallerSaved(array $saved): void
    {
        foreach (array_reverse($saved) as $reg) {
            $this->emitter->pop($this->emitter->reg($reg));
        }
    }

    /** @return string[] */
    private function collectUsedCalleeSaved(): array
    {
        if ($this->allocation === null) {
            return [];
        }

        $calleeSaved = CallingConvention::CALLEE_SAVED;
        $used        = [];

        foreach ($this->allocation->regMap as $physReg) {
            if (in_array($physReg, $calleeSaved, true) && !in_array($physReg, $used, true)) {
                $used[] = $physReg;
            }
        }

        return $used;
    }

    /**
     * Adds a double-precision constant to the deferred .rodata table and returns
     * its label. Deduplicates by bit pattern so identical values share one entry.
     */
    private function addFloatConst(int $bits, string $prefix = 'fc'): string
    {
        foreach ($this->floatConsts as $lbl => $existing) {
            if ($existing === $bits) {
                return $lbl;
            }
        }
        $lbl = '.LCf' . $this->floatConstCounter++ . '_' . $prefix;
        $this->floatConsts[$lbl] = $bits;
        return $lbl;
    }

    /** Reinterprets a PHP float as its IEEE 754 64-bit integer bit pattern. */
    private function doubleToInt(float $f): int
    {
        $packed = pack('d', $f);
        $arr    = unpack('Q', $packed);
        return (int)($arr[1] ?? 0);
    }

    private function labelName(Operand $op): string
    {
        return match ($op->kind) {
            OperandKind::Label    => (string)$op->value,
            OperandKind::FuncName => (string)$op->value,
            default               => (string)$op->value,
        };
    }

    /** @return string[] */
    private function splitBytes(string $data): array
    {
        $bytes = [];
        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $bytes[] = (string)ord($data[$i]);
        }
        return $bytes ?: ['0'];
    }
}
