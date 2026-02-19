<?php

declare(strict_types=1);

namespace Cppc\AST;

class AssignExpr extends Node
{
    public function __construct(
        public readonly Node $target,
        public readonly string $operator,
        public readonly Node $value,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "AssignExpr({$this->operator})\n";
        $out .= $this->target->dump($indent + 1);
        $out .= $this->value->dump($indent + 1);
        return $out;
    }
}
