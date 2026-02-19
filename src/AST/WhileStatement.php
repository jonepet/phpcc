<?php

declare(strict_types=1);

namespace Cppc\AST;

class WhileStatement extends Node
{
    public function __construct(
        public readonly Node $condition,
        public readonly Node $body,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "While\n";
        $out .= $this->pad($indent + 1) . "condition:\n";
        $out .= $this->condition->dump($indent + 2);
        $out .= $this->pad($indent + 1) . "body:\n";
        $out .= $this->body->dump($indent + 2);
        return $out;
    }
}
