<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class Relocation
{
    public function __construct(
        public string $section,
        public int $offset,
        public string $type,
        public string $target,
        public int $addend = 0,
    ) {}
}
