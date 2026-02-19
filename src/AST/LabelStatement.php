<?php

declare(strict_types=1);

namespace Cppc\AST;

class LabelStatement extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly Node $statement,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Label({$this->name})\n";
        $out .= $this->statement->dump($indent + 1);
        return $out;
    }
}
