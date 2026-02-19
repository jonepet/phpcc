<?php

declare(strict_types=1);

namespace Cppc\AST;

class DesignatedInit extends Node
{
    public function __construct(
        public readonly string $field,
        public readonly Node $value,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "DesignatedInit(.{$this->field})\n";
        $out .= $this->value->dump($indent + 1);
        return $out;
    }
}
