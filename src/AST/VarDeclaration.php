<?php

declare(strict_types=1);

namespace Cppc\AST;

class VarDeclaration extends Node
{
    public function __construct(
        public readonly TypeNode $type,
        public readonly string $name,
        public readonly ?Node $initializer = null,
        public readonly bool $isArray = false,
        public readonly ?Node $arraySize = null,
        public readonly ?InitializerList $arrayInit = null,
        public readonly ?int $bitWidth = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $arr = $this->isArray ? '[]' : '';
        $out = $this->pad($indent) . "VarDecl({$this->name}{$arr})\n";
        $out .= $this->type->dump($indent + 1);
        if ($this->initializer) {
            $out .= $this->pad($indent + 1) . "init:\n";
            $out .= $this->initializer->dump($indent + 2);
        }
        if ($this->arrayInit) {
            $out .= $this->pad($indent + 1) . "array_init:\n";
            $out .= $this->arrayInit->dump($indent + 2);
        }
        return $out;
    }
}
