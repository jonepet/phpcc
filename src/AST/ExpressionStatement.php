<?php

declare(strict_types=1);

namespace Cppc\AST;

class ExpressionStatement extends Node
{
    public function __construct(
        public readonly Node $expression,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "ExprStmt\n";
        $out .= $this->expression->dump($indent + 1);
        return $out;
    }
}
