<?php

declare(strict_types=1);

namespace Cppc\AST;

class FloatLiteral extends Node
{
    public function __construct(
        public readonly float $value,
    ) {}

    public function dump(int $indent = 0): string
    {
        return $this->pad($indent) . "FloatLiteral({$this->value})\n";
    }
}
