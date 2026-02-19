<?php

declare(strict_types=1);

namespace Cppc\AST;

class CommaExpr extends Node
{
    /** @param Node[] $expressions */
    public function __construct(
        public readonly array $expressions,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "CommaExpr\n";
        foreach ($this->expressions as $expr) {
            $out .= $expr->dump($indent + 1);
        }
        return $out;
    }
}
