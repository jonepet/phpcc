<?php

declare(strict_types=1);

namespace Cppc\AST;

class CastExpr extends Node
{
    public function __construct(
        public readonly TypeNode $targetType,
        public readonly Node $expression,
        public readonly string $castKind = 'c_style',
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "CastExpr({$this->castKind})\n";
        $out .= $this->targetType->dump($indent + 1);
        $out .= $this->expression->dump($indent + 1);
        return $out;
    }
}
