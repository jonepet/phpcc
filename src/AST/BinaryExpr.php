<?php

declare(strict_types=1);

namespace Cppc\AST;

class BinaryExpr extends Node
{
    public function __construct(
        public readonly Node $left,
        public readonly string $operator,
        public readonly Node $right,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "BinaryExpr({$this->operator})\n";
        $out .= $this->left->dump($indent + 1);
        $out .= $this->right->dump($indent + 1);
        return $out;
    }
}
