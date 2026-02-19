<?php

declare(strict_types=1);

namespace Cppc\AST;

class TemplateParameter extends Node
{
    public function __construct(
        public readonly string $name,
        public readonly bool $isTypename = true,
        public readonly ?TypeNode $defaultType = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $kind = $this->isTypename ? 'typename' : 'class';
        return $this->pad($indent) . "TemplateParam({$kind} {$this->name})\n";
    }
}
