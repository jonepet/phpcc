<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class Relocation
{
    // ELF relocation type constants
    public const R_X86_64_64           = 1;   // ABS64
    public const R_X86_64_PC32         = 2;   // REL32
    public const R_X86_64_PLT32        = 4;   // call to PLT entry
    public const R_X86_64_GOTPCREL     = 9;   // GOT-relative
    public const R_X86_64_32S             = 11;  // sign-extended 32-bit absolute
    public const R_X86_64_GOTPCRELX      = 41;  // relaxable GOT-relative (x86-64 psABI)
    public const R_X86_64_REX_GOTPCRELX  = 42;  // relaxable GOT-relative with REX prefix

    public function __construct(
        public string $section,
        public int $offset,
        public string $type,
        public string $target,
        public int $addend = 0,
    ) {}

    /**
     * Convert internal relocation type string to ELF relocation type constant.
     */
    public function elfType(): int
    {
        return match ($this->type) {
            'REL32' => self::R_X86_64_PC32,
            'ABS64' => self::R_X86_64_64,
            'PLT32' => self::R_X86_64_PLT32,
            'GOTPCREL' => self::R_X86_64_GOTPCREL,
            '32S' => self::R_X86_64_32S,
            default => 0,
        };
    }
}
