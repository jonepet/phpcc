<?php

declare(strict_types=1);

namespace Cppc\AST;

class MemberInitializerList extends Node
{
    /** @param MemberInitializer[] $initializers */
    public function __construct(
        public readonly array $initializers = [],
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "MemberInitList\n";
        foreach ($this->initializers as $init) {
            $out .= $init->dump($indent + 1);
        }
        return $out;
    }
}
