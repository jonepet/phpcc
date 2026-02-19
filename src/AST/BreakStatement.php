<?php

declare(strict_types=1);

namespace Cppc\AST;

class BreakStatement extends Node
{
    public function dump(int $indent = 0): string
    {
        return $this->pad($indent) . "Break\n";
    }
}
