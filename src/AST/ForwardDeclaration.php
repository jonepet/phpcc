<?php

declare(strict_types=1);

namespace Cppc\AST;

class ForwardDeclaration extends Node
{
    public function __construct(
        public readonly string $kind,    // 'struct', 'union', 'enum', 'class'
        public readonly string $name,
    ) {}

    public function dump(int $indent = 0): string
    {
        return $this->pad($indent) . "ForwardDecl({$this->kind} {$this->name})\n";
    }
}
