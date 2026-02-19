<?php

declare(strict_types=1);

namespace Cppc\AST;

class SwitchStatement extends Node
{
    /** @param CaseClause[] $cases */
    public function __construct(
        public readonly Node $expression,
        public readonly array $cases = [],
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Switch\n";
        $out .= $this->expression->dump($indent + 1);
        foreach ($this->cases as $case) {
            $out .= $case->dump($indent + 1);
        }
        return $out;
    }
}
