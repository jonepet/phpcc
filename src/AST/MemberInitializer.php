<?php

declare(strict_types=1);

namespace Cppc\AST;

class MemberInitializer extends Node
{
    /** @param Node[] $arguments */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments,
    ) {}

    public function dump(int $indent = 0): string
    {
        $out = $this->pad($indent) . "MemberInit({$this->name})\n";
        foreach ($this->arguments as $arg) {
            $out .= $arg->dump($indent + 1);
        }
        return $out;
    }
}
