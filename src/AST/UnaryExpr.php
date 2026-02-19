<?php

declare(strict_types=1);

namespace Cppc\AST;

class UnaryExpr extends Node
{
    public function __construct(
        public readonly string $operator,
        public readonly Node $operand,
        public readonly bool $prefix = true,
    ) {}

    public function dump(int $indent = 0): string
    {
        $pos = $this->prefix ? 'prefix' : 'postfix';
        $out = $this->pad($indent) . "UnaryExpr({$this->operator}, {$pos})\n";
        $out .= $this->operand->dump($indent + 1);
        return $out;
    }
}
