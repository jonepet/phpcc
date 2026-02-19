<?php

declare(strict_types=1);

namespace Cppc\AST;

class StringLiteralNode extends Node
{
    public function __construct(
        public readonly string $value,
    ) {}

    public function dump(int $indent = 0): string
    {
        return $this->pad($indent) . "StringLiteral(\"{$this->value}\")\n";
    }
}
