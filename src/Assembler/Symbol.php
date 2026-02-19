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
    ) {}
}
