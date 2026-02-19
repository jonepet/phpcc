<?php

declare(strict_types=1);

namespace Cppc\AST;

class NewExpr extends Node
{
    /** @param Node[] $arguments */
    public function __construct(
        public readonly TypeNode $type,
        public readonly array $arguments = [],
        public readonly bool $isArray = false,
        public readonly ?Node $arraySize = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $arr = $this->isArray ? '[]' : '';
        $out = $this->pad($indent) . "NewExpr{$arr}\n";
        $out .= $this->type->dump($indent + 1);
        foreach ($this->arguments as $arg) {
            $out .= $arg->dump($indent + 1);
        }
        return $out;
    }
}
