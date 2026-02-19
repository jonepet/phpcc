<?php

declare(strict_types=1);

namespace Cppc\AST;

class AccessSpecifier extends Node
{
    public function __construct(
        public readonly string $access,
    ) {}

    public function dump(int $indent = 0): string
    {
        return $this->pad($indent) . "AccessSpec({$this->access})\n";
    }
}
