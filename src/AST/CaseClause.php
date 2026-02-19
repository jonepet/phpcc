<?php

declare(strict_types=1);

namespace Cppc\AST;

class CaseClause extends Node
{
    /** @param Node[] $statements */
    public function __construct(
        public readonly ?Node $value,
        public readonly array $statements = [],
        public readonly bool $isDefault = false,
    ) {}

    public function dump(int $indent = 0): string
    {
        $label = $this->isDefault ? 'Default' : 'Case';
        $out = $this->pad($indent) . "{$label}\n";
        if ($this->value) {
            $out .= $this->value->dump($indent + 1);
        }
        foreach ($this->statements as $stmt) {
            $out .= $stmt->dump($indent + 1);
        }
        return $out;
    }
}
