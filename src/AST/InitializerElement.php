<?php

declare(strict_types=1);

namespace Cppc\AST;

class InitializerElement extends Node
{
    public function __construct(
        public readonly ?string $designator = null,
        public readonly ?int $index = null,
        public readonly ?Node $value = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $desig = '';
        if ($this->designator !== null) {
            $desig = ".{$this->designator}";
        } elseif ($this->index !== null) {
            $desig = "[{$this->index}]";
        }
        $out = $this->pad($indent) . "InitializerElement({$desig})\n";
        if ($this->value) {
            $out .= $this->value->dump($indent + 1);
        }
        return $out;
    }
}
