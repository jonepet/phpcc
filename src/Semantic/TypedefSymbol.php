<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

class TypedefSymbol extends Symbol
{
    public TypeNode $targetType;

    public function __construct(string $name, TypeNode $targetType)
    {
        parent::__construct($name, $targetType, SymbolKind::Typedef);
        $this->targetType = $targetType;
    }
}
