<?php

declare(strict_types=1);

namespace Cppc\AST;

class ClassDeclaration extends Node
{
    /** @param Node[] $members */
    public function __construct(
        public readonly string $name,
        public readonly array $members = [],
        public readonly bool $isStruct = false,
        public readonly ?string $baseClass = null,
        public readonly string $baseAccess = 'public',
        public readonly bool $isForwardDecl = false,
        public readonly bool $isUnion = false,
        public readonly ?string $typedefAlias = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $kind = $this->isUnion ? 'Union' : ($this->isStruct ? 'Struct' : 'Class');
        $base = $this->baseClass ? " : {$this->baseAccess} {$this->baseClass}" : '';
        $out = $this->pad($indent) . "{$kind}Decl({$this->name}{$base})\n";
        foreach ($this->members as $member) {
            $out .= $member->dump($indent + 1);
        }
        return $out;
    }
}
