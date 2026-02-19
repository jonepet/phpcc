<?php

declare(strict_types=1);

namespace Cppc\AST;

class Parameter extends Node
{
    public function __construct(
        public readonly TypeNode $type,
        public readonly string $name = '',
        public readonly ?Node $defaultValue = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Param({$this->name})\n";
        $out .= $this->type->dump($indent + 1);
        if ($this->defaultValue) {
            $out .= $this->pad($indent + 1) . "default:\n";
            $out .= $this->defaultValue->dump($indent + 2);
        }
        return $out;
    }
}
