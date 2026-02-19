<?php

declare(strict_types=1);

namespace Cppc\IR;

class Operand
{
    public function __construct(
        public readonly OperandKind $kind,
        public readonly int|float|string|null $value = null,
        public readonly int $size = 8,
    ) {}

    public function __toString(): string
    {
        return match ($this->kind) {
            OperandKind::VirtualReg => "t{$this->value}",
            OperandKind::Immediate => "#{$this->value}",
            OperandKind::FloatImm => "#f{$this->value}",
            OperandKind::Label => "L{$this->value}",
            OperandKind::Global => "@{$this->value}",
            OperandKind::String => "str\"{$this->value}\"",
            OperandKind::FuncName => "fn:{$this->value}",
            OperandKind::StackSlot => "[rbp-{$this->value}]",
            OperandKind::Param => "p{$this->value}",
        };
    }

    public static function vreg(int $id, int $size = 8): self
    {
        return new self(OperandKind::VirtualReg, $id, $size);
    }

    public static function imm(int $value): self
    {
        return new self(OperandKind::Immediate, $value);
    }

    public static function floatImm(float $value): self
    {
        return new self(OperandKind::FloatImm, $value);
    }

    public static function label(int|string $id): self
    {
        return new self(OperandKind::Label, $id);
    }

    public static function global(string $name): self
    {
        return new self(OperandKind::Global, $name);
    }

    public static function string(string $value): self
    {
        return new self(OperandKind::String, $value);
    }

    public static function funcName(string $name): self
    {
        return new self(OperandKind::FuncName, $name);
    }

    public static function stackSlot(int $offset): self
    {
        return new self(OperandKind::StackSlot, $offset);
    }

    public static function param(int $index): self
    {
        return new self(OperandKind::Param, $index);
    }
}
