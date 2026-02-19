<?php

declare(strict_types=1);

namespace Cppc\AST;

class IdentifierExpr extends Node
{
    public function __construct(
        public readonly string $name,
        public ?string $namespacePath = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $ns = $this->namespacePath ? "{$this->namespacePath}::" : '';
        return $this->pad($indent) . "Identifier({$ns}{$this->name})\n";
    }
}
