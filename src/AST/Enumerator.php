<?php

declare(strict_types=1);

namespace Cppc\AST;

class Enumerator extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly ?Node $value = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Enumerator({$this->name})\n";
        if ($this->value) {
            $out .= $this->value->dump($indent + 1);
        }
        return $out;
    }
}
