<?php

declare(strict_types=1);

namespace Cppc\CodeGen;

class LiveInterval
{
    public function __construct(
        public readonly int $vregId,
        public int $start,
        public int $end,
        public readonly bool $isFloat,
    ) {}

    public function length(): int
    {
        return $this->end - $this->start;
    }
}
