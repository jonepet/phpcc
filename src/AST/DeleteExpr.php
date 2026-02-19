<?php

declare(strict_types=1);

namespace Cppc\AST;

class DeleteExpr extends Node
{
    public function __construct(
        public readonly Node $operand,
        public readonly bool $isArray = false,
    ) {}

    public function dump(int $indent = 0): string
    {
        $arr = $this->isArray ? '[]' : '';
        $out = $this->pad($indent) . "DeleteExpr{$arr}\n";
        $out .= $this->operand->dump($indent + 1);
        return $out;
    }
}
