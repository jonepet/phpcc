<?php

declare(strict_types=1);

namespace Cppc\AST;

class TranslationUnit extends Node
{
    /** @param Node[] $declarations */
    public function __construct(
        public array $declarations = [],
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "TranslationUnit\n";
        foreach ($this->declarations as $decl) {
            $out .= $decl->dump($indent + 1);
        }
        return $out;
    }
}
