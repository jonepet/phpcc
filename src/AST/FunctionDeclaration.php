<?php

declare(strict_types=1);

namespace Cppc\AST;

class FunctionDeclaration extends Node
{
    /** @param Parameter[] $parameters */
    public function __construct(
        public readonly TypeNode $returnType,
        public readonly string $name,
        public readonly array $parameters = [],
        public readonly ?BlockStatement $body = null,
        public readonly bool $isVirtual = false,
        public readonly bool $isPureVirtual = false,
        public readonly bool $isStatic = false,
        public readonly bool $isInline = false,
        public readonly bool $isConst = false,
        public readonly ?string $className = null,
        public readonly bool $isOverride = false,
        public readonly bool $isConstructor = false,
        public readonly bool $isDestructor = false,
        public readonly ?MemberInitializerList $memberInitializers = null,
        public readonly ?string $linkage = null,
        public readonly bool $isVariadic = false,
    ) {}

    public function dump(int $indent = 0): string
    {
        $mods = [];
        if ($this->isVirtual) $mods[] = 'virtual';
        if ($this->isStatic) $mods[] = 'static';
        if ($this->isInline) $mods[] = 'inline';
        if ($this->isConst) $mods[] = 'const';
        if ($this->isPureVirtual) $mods[] = 'pure_virtual';
        $modStr = $mods ? ' [' . implode(', ', $mods) . ']' : '';
        $cls = $this->className ? "{$this->className}::" : '';
        $out = $this->pad($indent) . "FuncDecl({$cls}{$this->name}{$modStr})\n";
        $out .= $this->returnType->dump($indent + 1);
        foreach ($this->parameters as $param) {
            $out .= $param->dump($indent + 1);
        }
        if ($this->body) {
            $out .= $this->body->dump($indent + 1);
        }
        return $out;
    }

    public function isForwardDeclaration(): bool
    {
        return $this->body === null;
    }
}
