<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

class SymbolTable
{
    public ?SymbolTable $parent;
    /** @var array<string, Symbol> */
    public array $symbols = [];
    /** @var SymbolTable[] */
    public array $children = [];

    public function __construct(?SymbolTable $parent = null)
    {
        $this->parent = $parent;
    }

    public function define(Symbol $sym): void
    {
        if (isset($this->symbols[$sym->name])) {
            throw new \RuntimeException(
                "Redefinition of symbol '{$sym->name}' in the same scope"
            );
        }
        $this->symbols[$sym->name] = $sym;
    }

    public function lookup(string $name): ?Symbol
    {
        if (isset($this->symbols[$name])) {
            return $this->symbols[$name];
        }
        return $this->parent?->lookup($name);
    }

    public function lookupLocal(string $name): ?Symbol
    {
        return $this->symbols[$name] ?? null;
    }

    public function enterScope(): SymbolTable
    {
        $child = new SymbolTable($this);
        $this->children[] = $child;
        return $child;
    }

    public function exitScope(): SymbolTable
    {
        if ($this->parent === null) {
            throw new \RuntimeException('Cannot exit the root scope');
        }
        return $this->parent;
    }

    public function lookupClass(string $name): ?ClassSymbol
    {
        $sym = $this->lookup($name);
        if ($sym instanceof ClassSymbol) {
            return $sym;
        }
        return null;
    }

    public function lookupFunction(string $name): ?FunctionSymbol
    {
        $sym = $this->lookup($name);
        if ($sym instanceof FunctionSymbol) {
            return $sym;
        }
        return null;
    }

    public function lookupStruct(string $name): ?StructSymbol
    {
        $sym = $this->lookup($name);
        if ($sym instanceof StructSymbol) {
            return $sym;
        }
        return null;
    }

    public function lookupTypedef(string $name): ?TypedefSymbol
    {
        $sym = $this->lookup($name);
        if ($sym instanceof TypedefSymbol) {
            return $sym;
        }
        return null;
    }

    public function lookupEnum(string $name): ?EnumSymbol
    {
        $sym = $this->lookup($name);
        if ($sym instanceof EnumSymbol) {
            return $sym;
        }
        return null;
    }

    /**
     * Resolve a type name through typedefs to the underlying type.
     */
    public function resolveType(string $name): ?TypeNode
    {
        $sym = $this->lookup($name);
        if ($sym instanceof TypedefSymbol) {
            return $sym->targetType;
        }
        return null;
    }
}
