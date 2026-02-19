<?php

declare(strict_types=1);

namespace Cppc\AST;

class BlockStatement extends Node
{
    /** @param Node[] $statements */
    public function __construct(
        public array $statements = [],
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "Block\n";
        foreach ($this->statements as $stmt) {
            $out .= $stmt->dump($indent + 1);
        }
        return $out;
    }
}
