<?php

declare(strict_types=1);

namespace Cppc\AST;

class IfStatement extends Node
{
    public function __construct(
        public readonly Node $condition,
        public readonly Node $thenBranch,
        public readonly ?Node $elseBranch = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "If\n";
        $out .= $this->pad($indent + 1) . "condition:\n";
        $out .= $this->condition->dump($indent + 2);
        $out .= $this->pad($indent + 1) . "then:\n";
        $out .= $this->thenBranch->dump($indent + 2);
        if ($this->elseBranch) {
            $out .= $this->pad($indent + 1) . "else:\n";
            $out .= $this->elseBranch->dump($indent + 2);
        }
        return $out;
    }
}
