<?php

declare(strict_types=1);

namespace Cppc\AST;

class NamespaceDeclaration extends Node
{
    /** @param Node[] $declarations */
    public function __construct(
        public readonly string $name,
        public readonly array $declarations = [],
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Namespace({$this->name})\n";
        foreach ($this->declarations as $decl) {
            $out .= $decl->dump($indent + 1);
        }
        return $out;
    }
}
