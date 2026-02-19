<?php

declare(strict_types=1);

namespace Cppc\AST;

class IntLiteral extends Node
{
    public function __construct(
        public readonly int $value,
    ) {}

    public function dump(int $indent = 0): string
    {
        return $this->pad($indent) . "IntLiteral({$this->value})\n";
    }
}
