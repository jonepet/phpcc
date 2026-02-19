<?php

declare(strict_types=1);

namespace Cppc\AST;

abstract class Node
{
    public int $line = 0;
    public int $column = 0;
    public string $file = '';

    public function setLocation(int $line, int $column, string $file = ''): static
    {
        $this->line = $line;
        $this->column = $column;
        $this->file = $file;
        return $this;
    }

    abstract public function dump(int $indent = 0): string;

    protected function pad(int $indent): string
    {
        return str_repeat('  ', $indent);
    }
}
