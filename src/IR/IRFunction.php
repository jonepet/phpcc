<?php

declare(strict_types=1);

namespace Cppc\IR;

class IRFunction
{
    /** @var BasicBlock[] */
    public array $blocks = [];

    /** @var array<string, int> local variable stack offsets */
    public array $locals = [];

    public int $nextVReg = 0;
    public int $stackSize = 0;

    /** @var IRParam[] */
    public array $params = [];

    public function __construct(
        public readonly string $name,
        public readonly string $returnType = 'int',
        public readonly int $returnSize = 8,
        public readonly bool $returnIsFloat = false,
    ) {}

    public function newVReg(int $size = 8): Operand
    {
        return Operand::vreg($this->nextVReg++, $size);
    }

    public function newLabel(string $prefix = 'L'): string
    {
        static $counter = 0;
        return '.L' . $prefix . '_' . $counter++;
    }

    public function createBlock(string $label): BasicBlock
    {
        $block = new BasicBlock($label);
        $this->blocks[] = $block;
        return $block;
    }

    public function allocLocal(string $name, int $size = 8): int
    {
        // Always allocate at least 8 bytes per slot since we use 64-bit operations
        $allocSize = max($size, 8);
        $this->stackSize += $allocSize;
        $align = 8;
        $this->stackSize = ($this->stackSize + $align - 1) & ~($align - 1);
        $this->locals[$name] = $this->stackSize;
        return $this->stackSize;
    }

    public function __toString(): string
    {
        $out = "function {$this->name}:\n";
        foreach ($this->params as $p) {
            $out .= "  param {$p->name}: {$p->type} ({$p->size}B)\n";
        }
        $out .= "  stack_size: {$this->stackSize}\n";
        foreach ($this->blocks as $block) {
            $out .= $block;
        }
        return $out;
    }
}
