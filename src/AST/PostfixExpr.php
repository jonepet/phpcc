<?php

declare(strict_types=1);

namespace Cppc\AST;

class PostfixExpr extends Node
{
    public function __construct(
        public readonly Node $operand,
        public readonly string $operator,  // '++' or '--'
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "PostfixExpr({$this->operator})\n";
        $out .= $this->operand->dump($indent + 1);
        return $out;
    }
}
