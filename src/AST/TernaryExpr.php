<?php

declare(strict_types=1);

namespace Cppc\AST;

class TernaryExpr extends Node
{
    public function __construct(
        public readonly Node $condition,
        public readonly Node $trueExpr,
        public readonly Node $falseExpr,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "TernaryExpr\n";
        $out .= $this->pad($indent + 1) . "condition:\n";
        $out .= $this->condition->dump($indent + 2);
        $out .= $this->pad($indent + 1) . "true:\n";
        $out .= $this->trueExpr->dump($indent + 2);
        $out .= $this->pad($indent + 1) . "false:\n";
        $out .= $this->falseExpr->dump($indent + 2);
        return $out;
    }
}
