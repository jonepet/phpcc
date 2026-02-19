<?php

declare(strict_types=1);

namespace Cppc\AST;

class ReturnStatement extends Node
{
    public function __construct(
        public readonly ?Node $expression = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Return\n";
        if ($this->expression) {
            $out .= $this->expression->dump($indent + 1);
        }
        return $out;
    }
}
