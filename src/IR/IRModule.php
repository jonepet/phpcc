<?php

declare(strict_types=1);

namespace Cppc\IR;

class IRModule
{
    /** @var IRFunction[] */
    public array $functions = [];

    /** @var IRGlobal[] */
    public array $globals = [];

    /** @var array<string, string> string constants label => value */
    public array $strings = [];

    /** @var array<string, array{data: string, size: int}> vtables */
    public array $vtables = [];

    private int $stringCounter = 0;

    public function addFunction(IRFunction $func): void
    {
        $this->functions[] = $func;
    }

    public function addGlobal(string $name, string $type, int $size, ?string $initValue = null, ?string $stringData = null, bool $isLocal = false): void
    {
        $this->globals[] = new IRGlobal($name, $type, $size, $initValue, $stringData, $isLocal);
    }

    public function addString(string $value): string
    {
        foreach ($this->strings as $label => $existing) {
            if ($existing === $value) return $label;
        }
        $label = '.LC' . $this->stringCounter++;
        $this->strings[$label] = $value;
        return $label;
    }

    public function __toString(): string
    {
        $out = "=== IR Module ===\n";
        foreach ($this->globals as $g) {
            $out .= "global {$g->name}: {$g->type} ({$g->size}B)";
            if ($g->initValue !== null) $out .= " = {$g->initValue}";
            $out .= "\n";
        }
        foreach ($this->strings as $label => $value) {
            $out .= "string {$label}: \"{$value}\"\n";
        }
        $out .= "\n";
        foreach ($this->functions as $func) {
            $out .= $func . "\n";
        }
        return $out;
    }
}
