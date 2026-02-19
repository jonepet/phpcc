<?php

declare(strict_types=1);

namespace Cppc\AST;

class CharLiteralNode extends Node
{
    public function __construct(
        public readonly string $value,
        public readonly int $ordValue,
    ) {}

    public function dump(int $indent = 0): string
    {
        return $this->pad($indent) . "CharLiteral('{$this->value}')\n";
    }
}
