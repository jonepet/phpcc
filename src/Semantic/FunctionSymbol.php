<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

class FunctionSymbol extends Symbol
{
    /** @var TypeNode[] */
    public array $params = [];
    public TypeNode $returnType;
    public bool $isVirtual = false;
    public bool $isMethod = false;
    public bool $isVariadic = false;
    public string $className = '';

    public function __construct(
        string $name,
        TypeNode $returnType,
        TypeNode $type,
    ) {
        parent::__construct($name, $type, SymbolKind::Function);
        $this->returnType = $returnType;
    }
}
