<?php

declare(strict_types=1);

namespace Cppc\AST;

class InitializerList extends Node
{
    /** @param Node[] $values */
    public function __construct(
        public readonly array $values,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "InitializerList\n";
        foreach ($this->values as $val) {
            $out .= $val->dump($indent + 1);
        }
        return $out;
    }
}
