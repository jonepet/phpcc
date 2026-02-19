<?php

declare(strict_types=1);

namespace Cppc\AST;

class ArraySubscriptExpr extends Node
{
    public function __construct(
        public readonly Node $array,
        public readonly Node $index,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "ArraySubscriptExpr\n";
        $out .= $this->array->dump($indent + 1);
        $out .= $this->pad($indent + 1) . "index:\n";
        $out .= $this->index->dump($indent + 2);
        return $out;
    }
}
