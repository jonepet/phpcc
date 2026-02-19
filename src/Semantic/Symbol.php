<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

class Symbol
{
    public string $name;
    public TypeNode $type;
    public SymbolKind $kind;
    public bool $isConst = false;
    public bool $isStatic = false;
    public int $offset = 0;
    public string $mangledName = '';
    public ?ClassSymbol $classInfo = null;
    public ?int $enumValue = null;
    public ?int $bitWidth = null;
    public int $bitOffset = 0;

    public function __construct(
        string $name,
        TypeNode $type,
        SymbolKind $kind,
    ) {
        $this->name = $name;
        $this->type = $type;
        $this->kind = $kind;
        $this->mangledName = $name;
    }
}
