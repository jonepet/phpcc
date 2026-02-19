<?php

declare(strict_types=1);

namespace Cppc\AST;

class TemplateDeclaration extends Node
{
    /** @param TemplateParameter[] $parameters */
    public function __construct(
        public readonly array $parameters,
        public readonly Node $declaration,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Template\n";
        foreach ($this->parameters as $param) {
            $out .= $param->dump($indent + 1);
        }
        $out .= $this->declaration->dump($indent + 1);
        return $out;
    }
}
