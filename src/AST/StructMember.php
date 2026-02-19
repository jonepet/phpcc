<?php

declare(strict_types=1);

namespace Cppc\AST;

class StructMember extends Node
{
    public function __construct(
        public readonly TypeNode $type,
        public readonly string $name,
        public readonly ?int $bitWidth = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $bits = $this->bitWidth !== null ? " : {$this->bitWidth}" : '';
        $out = $this->pad($indent) . "StructMember({$this->name}{$bits})\n";
        $out .= $this->type->dump($indent + 1);
        return $out;
    }
}
