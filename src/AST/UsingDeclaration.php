<?php

declare(strict_types=1);

namespace Cppc\AST;

class UsingDeclaration extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isNamespace = false,
    ) {}

    public function dump(int $indent = 0): string
    {
        $kind = $this->isNamespace ? 'namespace' : 'name';
        return $this->pad($indent) . "Using({$kind} {$this->name})\n";
    }
}
