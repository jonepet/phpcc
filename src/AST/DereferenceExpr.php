<?php

declare(strict_types=1);

namespace Cppc\AST;

class DereferenceExpr extends Node
{
    public function __construct(
        public readonly Node $operand,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "DereferenceExpr\n";
        $out .= $this->operand->dump($indent + 1);
        return $out;
    }
}
