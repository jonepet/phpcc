<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class Symbol
{
    public function __construct(
        public string $name,
        public string $section,
        public int $offset,
        public bool $global = false,
        public string $type = 'notype',  // 'func', 'object', 'notype'
        public int $size = 0,
    ) {}

    /**
     * ELF st_info byte: (binding << 4) | type
     */
    public function elfStInfo(): int
    {
        $binding = $this->global ? 1 : 0; // STB_GLOBAL=1, STB_LOCAL=0
        $type = match ($this->type) {
            'func' => 2,    // STT_FUNC
            'object' => 1,  // STT_OBJECT
            default => 0,   // STT_NOTYPE
        };
        return ($binding << 4) | $type;
    }
}
