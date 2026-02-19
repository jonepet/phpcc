<?php

declare(strict_types=1);

namespace Cppc\AST;

class CompoundLiteralExpr extends Node
{
    /** @param InitializerElement[] $initializers */
    public function __construct(
        public readonly TypeNode $type,
        public readonly array $initializers,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "CompoundLiteralExpr\n";
        $out .= $this->type->dump($indent + 1);
        foreach ($this->initializers as $init) {
            $out .= $init->dump($indent + 1);
        }
        return $out;
    }
}
