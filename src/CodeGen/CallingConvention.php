<?php

declare(strict_types=1);

namespace Cppc\CodeGen;

class CallingConvention
{
    /** System V AMD64 ABI integer argument registers */
    public const INT_ARG_REGISTERS = ['rdi', 'rsi', 'rdx', 'rcx', 'r8', 'r9'];

    /** System V AMD64 ABI float argument registers */
    public const FLOAT_ARG_REGISTERS = ['xmm0', 'xmm1', 'xmm2', 'xmm3', 'xmm4', 'xmm5', 'xmm6', 'xmm7'];

    /** Callee-saved registers — must be preserved across calls */
    public const CALLEE_SAVED = ['rbx', 'r12', 'r13', 'r14', 'r15'];

    /** Caller-saved registers — may be clobbered by calls */
    public const CALLER_SAVED = ['rax', 'rcx', 'rdx', 'rsi', 'rdi', 'r8', 'r9', 'r10', 'r11'];

    /** Integer return register */
    public const INT_RETURN_REG = 'rax';

    /** Float return register */
    public const FLOAT_RETURN_REG = 'xmm0';

    /**
     * Returns the physical register for the given argument index, or null if the
     * argument must be passed on the stack.
     *
     * @param int  $index   Zero-based argument index (counting only int OR float separately)
     * @param bool $isFloat Whether this argument is a floating-point type
     */
    public function getArgRegister(int $index, bool $isFloat): ?string
    {
        if ($isFloat) {
            return self::FLOAT_ARG_REGISTERS[$index] ?? null;
        }

        return self::INT_ARG_REGISTERS[$index] ?? null;
    }

    /**
     * Returns the stack offset (relative to the caller's rsp at call time) for an
     * argument that must be passed on the stack.
     *
     * Stack args begin at [rsp+0] in the callee after the call instruction pushes the
     * return address. The offset returned here is the byte offset from the first
     * stack-passed argument slot (i.e. offset 0 = first stack arg).
     *
     * @param int $index      Overall zero-based argument index (across all types)
     * @param int $intCount   Number of integer registers already consumed
     * @param int $floatCount Number of float registers already consumed
     */
    public function getArgStackOffset(int $index, int $intCount, int $floatCount): int
    {
        // Determine how many arguments landed on the stack before this one.
        // Each stack argument occupies 8 bytes (aligned).
        $intOverflow   = max(0, $intCount   - count(self::INT_ARG_REGISTERS));
        $floatOverflow = max(0, $floatCount - count(self::FLOAT_ARG_REGISTERS));
        $stackArgIndex = $intOverflow + $floatOverflow;

        return $stackArgIndex * 8;
    }

    /**
     * Size of the red zone beneath the stack pointer that a leaf function may use
     * without adjusting rsp (System V AMD64 ABI §3.2.2).
     */
    public function getRedZoneSize(): int
    {
        return 128;
    }
}
