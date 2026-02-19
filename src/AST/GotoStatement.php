<?php

declare(strict_types=1);

namespace Cppc\AST;

class GotoStatement extends Node
{
    public function __construct(
        public readonly string $label,
    ) {}

    public function dump(int $indent = 0): string
    {
        return $this->pad($indent) . "Goto({$this->label})\n";
    }
}
