<?php

declare(strict_types=1);

namespace Cppc\AST;

class CallExpr extends Node
{
    /** @param Node[] $arguments */
    public function __construct(
        public readonly Node $callee,
        public readonly array $arguments = [],
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "CallExpr\n";
        $out .= $this->pad($indent + 1) . "callee:\n";
        $out .= $this->callee->dump($indent + 2);
        foreach ($this->arguments as $arg) {
            $out .= $this->pad($indent + 1) . "arg:\n";
            $out .= $arg->dump($indent + 2);
        }
        return $out;
    }
}
