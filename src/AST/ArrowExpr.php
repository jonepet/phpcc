<?php

declare(strict_types=1);

namespace Cppc\AST;

class ArrowExpr extends Node
{
    public function __construct(
        public readonly Node $object,
        public readonly string $member,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "ArrowExpr(->{$this->member})\n";
        $out .= $this->object->dump($indent + 1);
        return $out;
    }
}
