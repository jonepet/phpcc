<?php

declare(strict_types=1);

namespace Cppc\AST;

class ForStatement extends Node
{
    public function __construct(
        public readonly ?Node $init,
        public readonly ?Node $condition,
        public readonly ?Node $update,
        public readonly Node $body,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "For\n";
        if ($this->init) {
            $out .= $this->pad($indent + 1) . "init:\n";
            $out .= $this->init->dump($indent + 2);
        }
        if ($this->condition) {
            $out .= $this->pad($indent + 1) . "condition:\n";
            $out .= $this->condition->dump($indent + 2);
        }
        if ($this->update) {
            $out .= $this->pad($indent + 1) . "update:\n";
            $out .= $this->update->dump($indent + 2);
        }
        $out .= $this->pad($indent + 1) . "body:\n";
        $out .= $this->body->dump($indent + 2);
        return $out;
    }
}
