<?php

declare(strict_types=1);

namespace Cppc\IR;

class Instruction
{
    /** Whether this Call instruction targets a variadic function (SysV ABI: must set %al). */
    public bool $isVariadicCall = false;

    public function __construct(
        public readonly OpCode $opcode,
        public readonly ?Operand $dest = null,
        public readonly ?Operand $src1 = null,
        public readonly ?Operand $src2 = null,
        /** @var Operand[] extra operands for calls */
        public readonly array $extra = [],
        public readonly int $line = 0,
    ) {}

    public function __toString(): string
    {
        $parts = [$this->opcode->value];
        if ($this->dest) $parts[] = (string)$this->dest;
        if ($this->src1) $parts[] = (string)$this->src1;
        if ($this->src2) $parts[] = (string)$this->src2;
        foreach ($this->extra as $op) {
            $parts[] = (string)$op;
        }
        return implode(' ', $parts);
    }

    public function usesRegisters(): array
    {
        $regs = [];
        // For Store/StoreGlobal, dest is the address (a use, not a definition).
        if ($this->isStoreOp() && $this->dest?->kind === OperandKind::VirtualReg) {
            $regs[] = $this->dest->value;
        }
        if ($this->src1?->kind === OperandKind::VirtualReg) $regs[] = $this->src1->value;
        if ($this->src2?->kind === OperandKind::VirtualReg) $regs[] = $this->src2->value;
        foreach ($this->extra as $op) {
            if ($op->kind === OperandKind::VirtualReg) $regs[] = $op->value;
        }
        return $regs;
    }

    public function definesRegister(): ?int
    {
        // Store/StoreGlobal do not define their dest — it's an address operand.
        if ($this->isStoreOp()) {
            return null;
        }
        if ($this->dest?->kind === OperandKind::VirtualReg) {
            return $this->dest->value;
        }
        return null;
    }

    private function isStoreOp(): bool
    {
        return $this->opcode === OpCode::Store || $this->opcode === OpCode::StoreGlobal;
    }
}
