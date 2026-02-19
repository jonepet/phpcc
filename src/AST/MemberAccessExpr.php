<?php

declare(strict_types=1);

namespace Cppc\AST;

class MemberAccessExpr extends Node
{
    public function __construct(
        public readonly Node $object,
        public readonly string $member,
        public readonly bool $isArrow = false,
    ) {}

    public function dump(int $indent = 0): string
    {
        $op = $this->isArrow ? '->' : '.';
        $out = $this->pad($indent) . "MemberAccess({$op}{$this->member})\n";
        $out .= $this->object->dump($indent + 1);
        return $out;
    }
}
