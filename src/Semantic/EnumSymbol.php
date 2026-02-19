<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

class EnumSymbol extends Symbol
{
    /** @var array<string, int> name => value */
    public array $values = [];

    public function __construct(string $name)
    {
        $type = TypeNode::enum($name);
        parent::__construct($name, $type, SymbolKind::Enum);
    }

    /**
     * Add an enumerator value.
     */
    public function addValue(string $name, int $value): void
    {
        $this->values[$name] = $value;
    }
}
