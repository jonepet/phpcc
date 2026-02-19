<?php

declare(strict_types=1);

namespace Cppc\AST;

class ScopeResolutionExpr extends Node
{
    public function __construct(
        public readonly ?string $scope,
        public readonly string $name,
    ) {}

    public function dump(int $indent = 0): string
    {
        $s = $this->scope ?? '';
        return $this->pad($indent) . "ScopeResolution({$s}::{$this->name})\n";
    }
}
