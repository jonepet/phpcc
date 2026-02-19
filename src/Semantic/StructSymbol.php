<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

class StructSymbol extends Symbol
{
    /** @var array<string, array{type: TypeNode, offset: int, bitWidth: ?int}> */
    public array $members = [];
    public int $size = 0;
    public int $alignment = 1;
    public bool $isUnion = false;
    public bool $isComplete = false;

    public function __construct(string $name, bool $isUnion = false)
    {
        $type = $isUnion ? TypeNode::union($name) : TypeNode::struct($name);
        $kind = $isUnion ? SymbolKind::Class_ : SymbolKind::Class_;
        parent::__construct($name, $type, $kind);
        $this->isUnion = $isUnion;
    }

    /**
     * Find a member by name.
     * @return array{type: TypeNode, offset: int, bitWidth: ?int}|null
     */
    public function findMember(string $name): ?array
    {
        return $this->members[$name] ?? null;
    }

    /**
     * Add a member to this struct/union.
     */
    public function addMember(string $name, TypeNode $type, int $offset, ?int $bitWidth = null): void
    {
        $this->members[$name] = [
            'type' => $type,
            'offset' => $offset,
            'bitWidth' => $bitWidth,
        ];
    }
}
