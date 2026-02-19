<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

class ClassSymbol extends Symbol
{
    /** @var Symbol[] */
    public array $members = [];
    /** @var FunctionSymbol[] */
    public array $methods = [];
    public ?string $baseClass = null;
    /** @var array<int, FunctionSymbol> vtable slot => method */
    public array $vtable = [];
    public int $size = 0;
    public bool $isStruct = false;
    public bool $isUnion = false;

    public function __construct(string $name, bool $isUnion = false)
    {
        $type = new TypeNode(baseName: $name, className: $name);
        parent::__construct($name, $type, SymbolKind::Class_);
        $this->isUnion = $isUnion;
    }

    public function findMember(string $name): ?Symbol
    {
        foreach ($this->members as $member) {
            if ($member->name === $name) {
                return $member;
            }
        }
        return null;
    }

    public function findMethod(string $name): ?FunctionSymbol
    {
        foreach ($this->methods as $method) {
            if ($method->name === $name) {
                return $method;
            }
        }
        return null;
    }
}
