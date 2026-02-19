<?php

declare(strict_types=1);

namespace Cppc\AST;

class EnumDeclaration extends Node
{
    /** @param EnumEntry[] $entries */
    public function __construct(
        public readonly string $name,
        public readonly array $entries = [],
        public readonly bool $isScoped = false,
        public readonly ?TypeNode $underlyingType = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $scoped = $this->isScoped ? 'class ' : '';
        $out = $this->pad($indent) . "Enum({$scoped}{$this->name})\n";
        foreach ($this->entries as $entry) {
            $out .= $entry->dump($indent + 1);
        }
        return $out;
    }
}
