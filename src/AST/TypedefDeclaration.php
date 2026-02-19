<?php

declare(strict_types=1);

namespace Cppc\AST;

class TypedefDeclaration extends Node
{
    public function __construct(
        public readonly TypeNode $type,
        public readonly string $alias,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Typedef({$this->alias})\n";
        $out .= $this->type->dump($indent + 1);
        return $out;
    }
}
