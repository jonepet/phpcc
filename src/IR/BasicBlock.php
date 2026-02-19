<?php

declare(strict_types=1);

namespace Cppc\IR;

class BasicBlock
{
    /** @var Instruction[] */
    public array $instructions = [];

    /** @var BasicBlock[] */
    public array $successors = [];

    /** @var BasicBlock[] */
    public array $predecessors = [];

    public function __construct(
        public readonly string $label,
    ) {}

    public function addInstruction(Instruction $inst): void
    {
        $this->instructions[] = $inst;
    }

    public function addSuccessor(BasicBlock $block): void
    {
        $this->successors[] = $block;
        $block->predecessors[] = $this;
    }

    public function __toString(): string
    {
        $out = "{$this->label}:\n";
        foreach ($this->instructions as $inst) {
            $out .= "  {$inst}\n";
        }
        return $out;
    }
}
