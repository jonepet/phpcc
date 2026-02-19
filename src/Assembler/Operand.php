<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class Operand
{
    public OperandKind $kind;
    public string $reg = '';
    public int $imm = 0;
    public string $base = '';
    public string $index = '';
    public int $scale = 1;
    public int $disp = 0;
    public string $label = '';

    public static function register(string $reg): self
    {
        $o = new self();
        $o->kind = OperandKind::Register;
        $o->reg = $reg;
        return $o;
    }

    public static function immediate(int $val): self
    {
        $o = new self();
        $o->kind = OperandKind::Immediate;
        $o->imm = $val;
        return $o;
    }

    public static function memory(string $base, int $disp = 0): self
    {
        $o = new self();
        $o->kind = OperandKind::Memory;
        $o->base = $base;
        $o->disp = $disp;
        return $o;
    }

    public static function memSib(string $base, string $index, int $scale, int $disp = 0): self
    {
        $o = new self();
        $o->kind = OperandKind::MemSib;
        $o->base = $base;
        $o->index = $index;
        $o->scale = $scale;
        $o->disp = $disp;
        return $o;
    }

    public static function ripRel(string $label, int $disp = 0): self
    {
        $o = new self();
        $o->kind = OperandKind::RipRel;
        $o->label = $label;
        $o->disp = $disp;
        return $o;
    }

    public static function label(string $name): self
    {
        $o = new self();
        $o->kind = OperandKind::Label;
        $o->label = $name;
        return $o;
    }
}
