<?php

declare(strict_types=1);

namespace Cppc\AST;

class BoolLiteral extends Node
{
    public function __construct(
        public readonly bool $value,
    ) {}

    public function dump(int $indent = 0): string
    {
        $v = $this->value ? 'true' : 'false';
        return $this->pad($indent) . "BoolLiteral({$v})\n";
    }
}
