<?php

declare(strict_types=1);

namespace Cppc\AST;

class StructDeclaration extends Node
{
    /** @param StructMember[] $members */
    public function __construct(
        public readonly ?string $name,
        public readonly array $members,
        public readonly bool $isUnion = false,
    ) {}

    public function dump(int $indent = 0): string
    {
        $kind = $this->isUnion ? 'Union' : 'Struct';
        $name = $this->name ?? '<anonymous>';
        $out = $this->pad($indent) . "{$kind}Decl({$name})\n";
        foreach ($this->members as $member) {
            $out .= $member->dump($indent + 1);
        }
        return $out;
    }
}
