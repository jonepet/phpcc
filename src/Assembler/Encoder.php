<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class Encoder
{
    private string $bytes = '';
    /** @var Relocation[] */
    private array $relocs = [];
    /** @var array<string, int> */
    private array $labels = [];
    private string $sectionName = '';
    /** @var array<string, string> symbol name → type ('func','object','notype') */
    private array $symbolTypes = [];
    /** @var array<string, int> symbol name → size */
    private array $symbolSizes = [];

    private const REG_NUM = [
        'rax' => 0, 'eax' => 0, 'ax' => 0, 'al' => 0,
        'rcx' => 1, 'ecx' => 1, 'cx' => 1, 'cl' => 1,
        'rdx' => 2, 'edx' => 2, 'dx' => 2, 'dl' => 2,
        'rbx' => 3, 'ebx' => 3, 'bx' => 3, 'bl' => 3,
        'rsp' => 4, 'esp' => 4, 'sp' => 4, 'spl' => 4,
        'rbp' => 5, 'ebp' => 5, 'bp' => 5, 'bpl' => 5,
        'rsi' => 6, 'esi' => 6, 'si' => 6, 'sil' => 6,
        'rdi' => 7, 'edi' => 7, 'di' => 7, 'dil' => 7,
        'r8' => 8, 'r8d' => 8, 'r8w' => 8, 'r8b' => 8,
        'r9' => 9, 'r9d' => 9, 'r9w' => 9, 'r9b' => 9,
        'r10' => 10, 'r10d' => 10, 'r10w' => 10, 'r10b' => 10,
        'r11' => 11, 'r11d' => 11, 'r11w' => 11, 'r11b' => 11,
        'r12' => 12, 'r12d' => 12, 'r12w' => 12, 'r12b' => 12,
        'r13' => 13, 'r13d' => 13, 'r13w' => 13, 'r13b' => 13,
        'r14' => 14, 'r14d' => 14, 'r14w' => 14, 'r14b' => 14,
        'r15' => 15, 'r15d' => 15, 'r15w' => 15, 'r15b' => 15,
        'xmm0' => 0, 'xmm1' => 1, 'xmm2' => 2, 'xmm3' => 3,
        'xmm4' => 4, 'xmm5' => 5, 'xmm6' => 6, 'xmm7' => 7,
        'xmm8' => 8, 'xmm9' => 9, 'xmm10' => 10, 'xmm11' => 11,
        'xmm12' => 12, 'xmm13' => 13, 'xmm14' => 14, 'xmm15' => 15,
    ];

    /**
     * Encode a section's AsmLine items into machine code.
     *
     * @param AsmLine[] $lines
     */
    public function encode(string $sectionName, array $lines): SectionData
    {
        $this->bytes = '';
        $this->relocs = [];
        $this->labels = [];
        $this->sectionName = $sectionName;
        $this->symbolTypes = [];
        $this->symbolSizes = [];

        foreach ($lines as $line) {
            if ($line->label !== null) {
                $this->labels[$line->label] = strlen($this->bytes);
            }
            if ($line->directive !== null) {
                $this->encodeDirective($line);
            }
            if ($line->mnemonic !== null) {
                $this->encodeInstruction($line);
            }
        }

        $this->resolveLocalRelocs();

        $section = new SectionData($sectionName);
        $section->bytes = $this->bytes;
        $section->relocs = $this->relocs;
        foreach ($this->labels as $name => $offset) {
            $type = $this->symbolTypes[$name] ?? 'notype';
            $size = $this->symbolSizes[$name] ?? 0;
            $section->symbols[] = new Symbol($name, $sectionName, $offset, type: $type, size: $size);
        }
        return $section;
    }

    private function resolveLocalRelocs(): void
    {
        $remaining = [];
        foreach ($this->relocs as $reloc) {
            if ($reloc->type === 'REL32' && isset($this->labels[$reloc->target])) {
                $targetOff = $this->labels[$reloc->target];
                $patchOff = $reloc->offset;
                $rel = $targetOff - ($patchOff + 4) + $reloc->addend;
                $this->patchInt32($patchOff, $rel);
            } else {
                $remaining[] = $reloc;
            }
        }
        $this->relocs = $remaining;
    }

    // ── Directives ──────────────────────────────────────────────────────────

    private function encodeDirective(AsmLine $line): void
    {
        switch ($line->directive) {
            case 'byte':
                $this->emit8($line->directiveArgs & 0xFF);
                break;
            case 'word':
                $this->emitLE16($line->directiveArgs);
                break;
            case 'long':
                $this->emitLE32($line->directiveArgs);
                break;
            case 'quad':
                if (is_string($line->directiveArgs)) {
                    // Symbol reference — ABS64 relocation
                    $this->relocs[] = new Relocation(
                        $this->sectionName,
                        strlen($this->bytes),
                        'ABS64',
                        $line->directiveArgs,
                    );
                    $this->emitLE64(0);
                } else {
                    $this->emitLE64($line->directiveArgs);
                }
                break;
            case 'asciz':
                $s = $line->directiveArgs;
                for ($i = 0; $i < strlen($s); $i++) {
                    $this->emit8(ord($s[$i]));
                }
                $this->emit8(0); // null terminator
                break;
            case 'zero':
                $this->bytes .= str_repeat("\0", $line->directiveArgs);
                break;
            case 'align':
                $align = $line->directiveArgs;
                $cur = strlen($this->bytes);
                $pad = ($align - ($cur % $align)) % $align;
                $this->bytes .= str_repeat("\0", $pad);
                break;
            case 'type_meta':
                // [symbolName, typeName] e.g. ['main', 'function']
                $args = $line->directiveArgs;
                $elfType = match ($args[1]) {
                    'function' => 'func',
                    'object' => 'object',
                    default => 'notype',
                };
                $this->symbolTypes[$args[0]] = $elfType;
                break;
            case 'size_meta':
                // [symbolName, sizeExpr] e.g. ['main', '.-main']
                $args = $line->directiveArgs;
                $sizeExpr = $args[1];
                // Handle '.-symbolName' pattern
                if (str_starts_with($sizeExpr, '.-')) {
                    $refLabel = substr($sizeExpr, 2);
                    if (isset($this->labels[$refLabel])) {
                        $this->symbolSizes[$args[0]] = strlen($this->bytes) - $this->labels[$refLabel];
                    }
                } elseif (ctype_digit($sizeExpr)) {
                    $this->symbolSizes[$args[0]] = (int)$sizeExpr;
                }
                break;
        }
    }

    // ── Instruction dispatch ────────────────────────────────────────────────

    private function encodeInstruction(AsmLine $line): void
    {
        $ops = $line->operands;
        [$mn, $size] = $this->normalizeMnemonic($line->mnemonic, $ops);

        match ($mn) {
            'mov'   => $this->encodeMov($ops, $size),
            'add'   => $this->encodeALU(0x01, 0x03, 0, $ops, $size),
            'sub'   => $this->encodeALU(0x29, 0x2B, 5, $ops, $size),
            'and'   => $this->encodeALU(0x21, 0x23, 4, $ops, $size),
            'or'    => $this->encodeALU(0x09, 0x0B, 1, $ops, $size),
            'xor'   => $this->encodeALU(0x31, 0x33, 6, $ops, $size),
            'cmp'   => $this->encodeALU(0x39, 0x3B, 7, $ops, $size),
            'test'  => $this->encodeTest($ops, $size),
            'push'  => $this->encodePush($ops[0]),
            'pop'   => $this->encodePop($ops[0]),
            'lea'   => $this->encodeLea($ops[0], $ops[1]),
            'imul'  => $this->encodeImul($ops, $size),
            'idiv'  => $this->encodeUnary(7, $ops[0], $size),
            'div'   => $this->encodeUnary(6, $ops[0], $size),
            'neg'   => $this->encodeUnary(3, $ops[0], $size),
            'not'   => $this->encodeUnary(2, $ops[0], $size),
            'inc'   => $this->encodeIncDec(0, $ops[0], $size),
            'dec'   => $this->encodeIncDec(1, $ops[0], $size),
            'shl'   => $this->encodeShift(4, $ops, $size),
            'sar'   => $this->encodeShift(7, $ops, $size),
            'shr'   => $this->encodeShift(5, $ops, $size),
            'call'  => $this->encodeCall($ops[0]),
            'ret'   => $this->emit8(0xC3),
            'jmp'   => $this->encodeJmp($ops[0]),
            'je'    => $this->encodeJcc(0x84, $ops[0]),
            'jne'   => $this->encodeJcc(0x85, $ops[0]),
            'jl'    => $this->encodeJcc(0x8C, $ops[0]),
            'jle'   => $this->encodeJcc(0x8E, $ops[0]),
            'jg'    => $this->encodeJcc(0x8F, $ops[0]),
            'jge'   => $this->encodeJcc(0x8D, $ops[0]),
            'jb'    => $this->encodeJcc(0x82, $ops[0]),
            'jbe'   => $this->encodeJcc(0x86, $ops[0]),
            'ja'    => $this->encodeJcc(0x87, $ops[0]),
            'jae'   => $this->encodeJcc(0x83, $ops[0]),
            'cqo'   => $this->emitBytes([0x48, 0x99]),
            'cdq'   => $this->emit8(0x99),
            'syscall' => $this->emitBytes([0x0F, 0x05]),
            'leave' => $this->emit8(0xC9),
            'nop'   => $this->emit8(0x90),
            'sete'  => $this->encodeSetcc(0x94, $ops[0]),
            'setne' => $this->encodeSetcc(0x95, $ops[0]),
            'setl'  => $this->encodeSetcc(0x9C, $ops[0]),
            'setle' => $this->encodeSetcc(0x9E, $ops[0]),
            'setg'  => $this->encodeSetcc(0x9F, $ops[0]),
            'setge' => $this->encodeSetcc(0x9D, $ops[0]),
            'setb'  => $this->encodeSetcc(0x92, $ops[0]),
            'setbe' => $this->encodeSetcc(0x96, $ops[0]),
            'seta'  => $this->encodeSetcc(0x97, $ops[0]),
            'setae' => $this->encodeSetcc(0x93, $ops[0]),
            'movzx' => $this->encodeMovzx($ops, $size),
            'movsx' => $this->encodeMovsx($ops, $size),
            'movsxd' => $this->encodeMovsxd($ops),
            'movsd'  => $this->encodeSSEsd(0x10, 0x11, $ops),
            'addsd'  => $this->encodeSSEOp(0xF2, 0x58, $ops),
            'subsd'  => $this->encodeSSEOp(0xF2, 0x5C, $ops),
            'mulsd'  => $this->encodeSSEOp(0xF2, 0x59, $ops),
            'divsd'  => $this->encodeSSEOp(0xF2, 0x5E, $ops),
            'xorpd'  => $this->encodeSSEOp(0x66, 0x57, $ops),
            'ucomisd' => $this->encodeSSEOp(0x66, 0x2E, $ops),
            'cvtsi2sd' => $this->encodeCvtsi2sd($ops),
            'cvtsd2si' => $this->encodeCvtsd2si($ops),
            'movq_sse' => $this->encodeMovqSSE($ops),
            default => throw new \RuntimeException("Unknown mnemonic: {$mn}"),
        };
    }

    // ── Mnemonic normalization ──────────────────────────────────────────────

    /**
     * @return array{string, int}
     */
    private function normalizeMnemonic(string $mn, array $ops): array
    {
        // SSE mnemonics — return as-is
        $sse = ['movsd','addsd','subsd','mulsd','divsd','xorpd','ucomisd',
                'cvtsi2sd','cvtsd2si'];
        if (in_array($mn, $sse, true)) {
            return [$mn, 0];
        }

        // movq: SSE if xmm operand, else 64-bit integer mov
        if ($mn === 'movq') {
            foreach ($ops as $op) {
                if ($op->kind === OperandKind::Register && str_starts_with($op->reg, 'xmm')) {
                    return ['movq_sse', 0];
                }
            }
            return ['mov', 8];
        }

        // AT&T movzbl/movzwl/movzbq/movzwq → movzx with explicit source size
        // AT&T movsbl/movswl/movsbq/movswq/movslq → movsx with explicit source size
        $attMovzx = [
            'movzbl' => [1, 4], 'movzbq' => [1, 8],
            'movzwl' => [2, 4], 'movzwq' => [2, 8],
        ];
        if (isset($attMovzx[$mn])) {
            return ['movzx', $attMovzx[$mn][0]];  // size = srcSize
        }
        $attMovsx = [
            'movsbl' => [1, 4], 'movsbq' => [1, 8],
            'movswl' => [2, 4], 'movswq' => [2, 8],
            'movslq' => [4, 8],
        ];
        if (isset($attMovsx[$mn])) {
            return [$mn === 'movslq' ? 'movsxd' : 'movsx', $attMovsx[$mn][0]];
        }

        // Aliases
        if ($mn === 'jz')     return ['je', 8];
        if ($mn === 'jnz')    return ['jne', 8];
        if ($mn === 'leaveq') return ['leave', 8];

        // Known base mnemonics that don't need suffix stripping
        $bases = ['mov','add','sub','and','or','xor','cmp','test',
                  'push','pop','lea','imul','idiv','div','neg','not',
                  'inc','dec','shl','sar','shr','call','ret','jmp',
                  'je','jne','jl','jle','jg','jge','jb','jbe','ja','jae',
                  'cqo','cdq','syscall','leave','nop',
                  'sete','setne','setl','setle','setg','setge',
                  'setb','setbe','seta','setae',
                  'movzx','movsx','movsxd',
                  'movsd','addsd','subsd','mulsd','divsd',
                  'xorpd','ucomisd','cvtsi2sd','cvtsd2si'];
        if (in_array($mn, $bases, true)) {
            return [$mn, $this->inferSize($ops)];
        }

        // Try stripping size suffix
        $suffixes = ['b' => 1, 'w' => 2, 'l' => 4, 'q' => 8];
        $last = $mn[strlen($mn) - 1];
        if (isset($suffixes[$last])) {
            $base = substr($mn, 0, -1);
            $tryBases = ['mov','add','sub','and','or','xor','cmp','test',
                         'push','pop','neg','not','inc','dec','div','idiv',
                         'lea','leave','shl','sar','shr','imul'];
            if (in_array($base, $tryBases, true)) {
                return [$base, $suffixes[$last]];
            }
        }

        return [$mn, $this->inferSize($ops)];
    }

    private function inferSize(array $ops): int
    {
        foreach ($ops as $op) {
            if ($op->kind === OperandKind::Register) {
                return $this->regSize($op->reg);
            }
        }
        return 8;
    }

    private function regSize(string $reg): int
    {
        if (str_starts_with($reg, 'xmm')) return 8;
        if (in_array($reg, ['al','bl','cl','dl','spl','bpl','sil','dil',
                             'r8b','r9b','r10b','r11b','r12b','r13b','r14b','r15b'], true)) return 1;
        if (in_array($reg, ['ax','bx','cx','dx','sp','bp','si','di',
                             'r8w','r9w','r10w','r11w','r12w','r13w','r14w','r15w'], true)) return 2;
        if (in_array($reg, ['eax','ebx','ecx','edx','esp','ebp','esi','edi',
                             'r8d','r9d','r10d','r11d','r12d','r13d','r14d','r15d'], true)) return 4;
        return 8;
    }

    // ── MOV ─────────────────────────────────────────────────────────────────

    private function encodeMov(array $ops, int $size): void
    {
        $src = $ops[0];
        $dst = $ops[1];

        if ($src->kind === OperandKind::Immediate) {
            if ($dst->kind === OperandKind::Register) {
                $this->encodeMovImmReg($src->imm, $dst->reg, $size);
            } else {
                $this->encodeMovImmMem($src->imm, $dst, $size);
            }
        } elseif ($src->kind === OperandKind::Register) {
            // reg → reg/mem  (opcode: 0x89 for 16/32/64, 0x88 for 8-bit)
            $regNum = $this->regNum($src->reg);
            $opcode = ($size === 1) ? 0x88 : 0x89;
            $this->emitSizePrefix($size);
            $this->emitRex($size >= 8, $regNum, $dst, $size, $src->reg);
            $this->emit8($opcode);
            $this->emitModRM($regNum, $dst);
        } else {
            // mem → reg  (opcode: 0x8B for 16/32/64, 0x8A for 8-bit)
            $regNum = $this->regNum($dst->reg);
            $opcode = ($size === 1) ? 0x8A : 0x8B;
            $this->emitSizePrefix($size);
            $this->emitRex($size >= 8, $regNum, $src, $size, $dst->reg);
            $this->emit8($opcode);
            $this->emitModRM($regNum, $src);
        }
    }

    private function encodeMovImmReg(int $imm, string $reg, int $size): void
    {
        $rn = $this->regNum($reg);

        if ($size === 1) {
            // mov imm8, r8: 0xB0+rb
            if ($rn >= 8 || $this->needsRexByte($reg)) {
                $this->emit8(0x40 | (($rn >> 3) & 1));
            }
            $this->emit8(0xB0 + ($rn & 7));
            $this->emit8($imm & 0xFF);
            return;
        }

        if ($size === 2) {
            $this->emit8(0x66);
            if ($rn >= 8) {
                $this->emit8(0x41);
            }
            $this->emit8(0xB8 + ($rn & 7));
            $this->emitLE16($imm);
            return;
        }

        if ($size === 4 || ($size === 8 && $imm >= 0 && $imm <= 0x7FFFFFFF)) {
            // Use 32-bit mov which zero-extends to 64-bit for non-negative values
            if ($size === 8 && $imm >= 0 && $imm <= 0x7FFFFFFF) {
                // mov $imm32, %eXX (zero-extends to 64-bit)
                if ($rn >= 8) {
                    $this->emit8(0x41);
                }
                $this->emit8(0xB8 + ($rn & 7));
                $this->emitLE32($imm);
                return;
            }
            if ($rn >= 8) {
                $this->emit8(0x41);
            }
            $this->emit8(0xB8 + ($rn & 7));
            $this->emitLE32($imm);
            return;
        }

        // 64-bit: if fits in signed 32-bit, use REX.W + C7 /0
        if ($imm >= -2147483648 && $imm <= 2147483647) {
            $this->emit8(0x48 | (($rn >> 3) & 1));
            $this->emit8(0xC7);
            $this->emit8(0xC0 | ($rn & 7));
            $this->emitLE32($imm);
            return;
        }

        // Full 64-bit: REX.W + B8+rd + imm64
        $this->emit8(0x48 | (($rn >> 3) & 1));
        $this->emit8(0xB8 + ($rn & 7));
        $this->emitLE64($imm);
    }

    private function encodeMovImmMem(int $imm, Operand $dst, int $size): void
    {
        if ($size === 1) {
            // movb $imm, mem: 0xC6 /0
            $this->emitRex(false, 0, $dst, 1);
            $this->emit8(0xC6);
            $this->emitModRM(0, $dst);
            $this->emit8($imm & 0xFF);
            return;
        }

        $this->emitSizePrefix($size);
        $this->emitRex($size >= 8, 0, $dst, $size);
        $this->emit8(($size === 1) ? 0xC6 : 0xC7);
        $this->emitModRM(0, $dst);

        if ($size === 2) {
            $this->emitLE16($imm);
        } else {
            $this->emitLE32($imm); // sign-extended to 64 if REX.W
        }
    }

    // ── ALU (add/sub/and/or/xor/cmp) ───────────────────────────────────────

    private function encodeALU(int $opcodeToRM, int $opcodeFromRM, int $immExt, array $ops, int $size): void
    {
        $src = $ops[0];
        $dst = $ops[1];

        if ($src->kind === OperandKind::Immediate) {
            $imm = $src->imm;
            if ($size === 1) {
                $this->emitRex(false, $immExt, $dst, 1);
                $this->emit8(0x80);
                $this->emitModRM($immExt, $dst);
                $this->emit8($imm & 0xFF);
            } else {
                $this->emitSizePrefix($size);
                $this->emitRex($size >= 8, $immExt, $dst, $size);
                if ($imm >= -128 && $imm <= 127) {
                    $this->emit8(0x83);
                    $this->emitModRM($immExt, $dst);
                    $this->emit8($imm & 0xFF);
                } else {
                    $this->emit8(0x81);
                    $this->emitModRM($immExt, $dst);
                    $this->emitLE32($imm);
                }
            }
        } elseif ($src->kind === OperandKind::Register) {
            // reg → reg/mem
            $rn = $this->regNum($src->reg);
            $opcode = ($size === 1) ? ($opcodeToRM - 1) : $opcodeToRM;
            $this->emitSizePrefix($size);
            $this->emitRex($size >= 8, $rn, $dst, $size, $src->reg);
            $this->emit8($opcode);
            $this->emitModRM($rn, $dst);
        } else {
            // mem → reg
            $rn = $this->regNum($dst->reg);
            $opcode = ($size === 1) ? ($opcodeFromRM - 1) : $opcodeFromRM;
            $this->emitSizePrefix($size);
            $this->emitRex($size >= 8, $rn, $src, $size, $dst->reg);
            $this->emit8($opcode);
            $this->emitModRM($rn, $src);
        }
    }

    // ── TEST ────────────────────────────────────────────────────────────────

    private function encodeTest(array $ops, int $size): void
    {
        $src = $ops[0];
        $dst = $ops[1];

        if ($src->kind === OperandKind::Immediate) {
            // test $imm, r/m
            $this->emitSizePrefix($size);
            $this->emitRex($size >= 8, 0, $dst, $size);
            $this->emit8(($size === 1) ? 0xF6 : 0xF7);
            $this->emitModRM(0, $dst);
            if ($size === 1) {
                $this->emit8($src->imm & 0xFF);
            } elseif ($size === 2) {
                $this->emitLE16($src->imm);
            } else {
                $this->emitLE32($src->imm);
            }
        } else {
            // test reg, r/m
            $rn = $this->regNum($src->reg);
            $opcode = ($size === 1) ? 0x84 : 0x85;
            $this->emitSizePrefix($size);
            $this->emitRex($size >= 8, $rn, $dst, $size);
            $this->emit8($opcode);
            $this->emitModRM($rn, $dst);
        }
    }

    // ── PUSH / POP ──────────────────────────────────────────────────────────

    private function encodePush(Operand $op): void
    {
        $rn = $this->regNum($op->reg);
        if ($rn >= 8) {
            $this->emit8(0x41);
        }
        $this->emit8(0x50 + ($rn & 7));
    }

    private function encodePop(Operand $op): void
    {
        $rn = $this->regNum($op->reg);
        if ($rn >= 8) {
            $this->emit8(0x41);
        }
        $this->emit8(0x58 + ($rn & 7));
    }

    // ── LEA ─────────────────────────────────────────────────────────────────

    private function encodeLea(Operand $mem, Operand $reg): void
    {
        $rn = $this->regNum($reg->reg);
        $this->emitRex(true, $rn, $mem, 8);
        $this->emit8(0x8D);
        $this->emitModRM($rn, $mem);
    }

    // ── IMUL ────────────────────────────────────────────────────────────────

    private function encodeImul(array $ops, int $size): void
    {
        if (count($ops) === 2 && $ops[0]->kind === OperandKind::Immediate) {
            // imul $imm, %reg → three-operand form with same src/dst
            $imm = $ops[0]->imm;
            $rn = $this->regNum($ops[1]->reg);
            $this->emitRex($size >= 8, $rn, $ops[1], $size);
            if ($imm >= -128 && $imm <= 127) {
                $this->emit8(0x6B);
                $this->emit8(0xC0 | (($rn & 7) << 3) | ($rn & 7));
                $this->emit8($imm & 0xFF);
            } else {
                $this->emit8(0x69);
                $this->emit8(0xC0 | (($rn & 7) << 3) | ($rn & 7));
                $this->emitLE32($imm);
            }
        } else {
            // imul r/m, %reg → two-operand form: 0x0F 0xAF
            $dstRn = $this->regNum($ops[1]->reg);
            $this->emitSizePrefix($size);
            $this->emitRex($size >= 8, $dstRn, $ops[0], $size);
            $this->emit8(0x0F);
            $this->emit8(0xAF);
            $this->emitModRM($dstRn, $ops[0]);
        }
    }

    // ── Unary: neg/not/idiv/div ─────────────────────────────────────────────

    private function encodeUnary(int $ext, Operand $op, int $size): void
    {
        $this->emitSizePrefix($size);
        $this->emitRex($size >= 8, $ext, $op, $size);
        $this->emit8(($size === 1) ? 0xF6 : 0xF7);
        $this->emitModRM($ext, $op);
    }

    // ── INC / DEC ───────────────────────────────────────────────────────────

    private function encodeIncDec(int $ext, Operand $op, int $size): void
    {
        $this->emitSizePrefix($size);
        $this->emitRex($size >= 8, $ext, $op, $size);
        $this->emit8(($size === 1) ? 0xFE : 0xFF);
        $this->emitModRM($ext, $op);
    }

    // ── Shifts ──────────────────────────────────────────────────────────────

    private function encodeShift(int $ext, array $ops, int $size): void
    {
        $count = $ops[0];
        $dst = $ops[1];

        $this->emitSizePrefix($size);
        $this->emitRex($size >= 8, $ext, $dst, $size);

        if ($count->kind === OperandKind::Register && $count->reg === 'cl') {
            $this->emit8(($size === 1) ? 0xD2 : 0xD3);
        } elseif ($count->kind === OperandKind::Immediate && $count->imm === 1) {
            $this->emit8(($size === 1) ? 0xD0 : 0xD1);
        } else {
            $this->emit8(($size === 1) ? 0xC0 : 0xC1);
        }

        $this->emitModRM($ext, $dst);

        if ($count->kind === OperandKind::Immediate && $count->imm !== 1) {
            $this->emit8($count->imm & 0xFF);
        }
    }

    // ── Control flow ────────────────────────────────────────────────────────

    private function encodeCall(Operand $op): void
    {
        if ($op->kind === OperandKind::Label) {
            $this->emit8(0xE8);
            $relocType = ($op->suffix === 'PLT') ? 'PLT32' : 'REL32';
            $this->relocs[] = new Relocation(
                $this->sectionName,
                strlen($this->bytes),
                $relocType,
                $op->label,
            );
            $this->emitLE32(0);
        } else {
            // call *%reg (indirect)
            $rn = $this->regNum($op->reg);
            if ($rn >= 8) {
                $this->emit8(0x41);
            }
            $this->emit8(0xFF);
            $this->emit8(0xD0 | ($rn & 7));
        }
    }

    private function encodeJmp(Operand $op): void
    {
        if ($op->kind === OperandKind::Label) {
            $this->emit8(0xE9);
            $relocType = ($op->suffix === 'PLT') ? 'PLT32' : 'REL32';
            $this->relocs[] = new Relocation(
                $this->sectionName,
                strlen($this->bytes),
                $relocType,
                $op->label,
            );
            $this->emitLE32(0);
        } else {
            // jmp *%reg
            $rn = $this->regNum($op->reg);
            if ($rn >= 8) {
                $this->emit8(0x41);
            }
            $this->emit8(0xFF);
            $this->emit8(0xE0 | ($rn & 7));
        }
    }

    private function encodeJcc(int $cc, Operand $op): void
    {
        $this->emit8(0x0F);
        $this->emit8($cc);
        $this->relocs[] = new Relocation(
            $this->sectionName,
            strlen($this->bytes),
            'REL32',
            $op->label,
        );
        $this->emitLE32(0);
    }

    // ── SETcc ───────────────────────────────────────────────────────────────

    private function encodeSetcc(int $cc, Operand $op): void
    {
        $rn = $this->regNum($op->reg);
        // SETcc needs REX if using spl/bpl/sil/dil or extended registers
        $needRex = ($rn >= 8) || $this->needsRexByte($op->reg);
        if ($needRex) {
            $this->emit8(0x40 | (($rn >> 3) & 1));
        }
        $this->emit8(0x0F);
        $this->emit8($cc);
        $this->emit8(0xC0 | ($rn & 7));
    }

    // ── MOVZX / MOVSX / MOVSXD ─────────────────────────────────────────────

    private function encodeMovzx(array $ops, int $hintSrcSize = 0): void
    {
        $src = $ops[0];
        $dst = $ops[1];
        $srcSize = $hintSrcSize > 0 ? $hintSrcSize
            : (($src->kind === OperandKind::Register) ? $this->regSize($src->reg) : 1);
        $dstSize = ($dst->kind === OperandKind::Register) ? $this->regSize($dst->reg) : 8;
        $dstRn = $this->regNum($dst->reg);

        // REX.W for 64-bit destination
        $w = ($dstSize === 8);
        $this->emitRex($w, $dstRn, $src, $dstSize);
        $this->emit8(0x0F);
        $this->emit8($srcSize === 1 ? 0xB6 : 0xB7);
        $this->emitModRM($dstRn, $src);
    }

    private function encodeMovsx(array $ops, int $hintSrcSize = 0): void
    {
        $src = $ops[0];
        $dst = $ops[1];
        $srcSize = $hintSrcSize > 0 ? $hintSrcSize
            : (($src->kind === OperandKind::Register) ? $this->regSize($src->reg) : 1);
        $dstSize = ($dst->kind === OperandKind::Register) ? $this->regSize($dst->reg) : 8;
        $dstRn = $this->regNum($dst->reg);

        if ($srcSize === 4 && $dstSize === 8) {
            // movsxd: REX.W + 0x63
            $this->emitRex(true, $dstRn, $src, 8);
            $this->emit8(0x63);
        } else {
            $w = ($dstSize === 8);
            $this->emitRex($w, $dstRn, $src, $dstSize);
            $this->emit8(0x0F);
            $this->emit8($srcSize === 1 ? 0xBE : 0xBF);
        }
        $this->emitModRM($dstRn, $src);
    }

    private function encodeMovsxd(array $ops): void
    {
        $src = $ops[0];
        $dst = $ops[1];
        $dstRn = $this->regNum($dst->reg);
        $this->emitRex(true, $dstRn, $src, 8);
        $this->emit8(0x63);
        $this->emitModRM($dstRn, $src);
    }

    // ── SSE2 instructions ───────────────────────────────────────────────────

    private function encodeSSEsd(int $loadOp, int $storeOp, array $ops): void
    {
        $src = $ops[0];
        $dst = $ops[1];

        // movsd: F2 0F 10 for load (xmm←xmm/mem), F2 0F 11 for store (xmm→mem)
        if ($dst->kind !== OperandKind::Register || !str_starts_with($dst->reg, 'xmm')) {
            // store: src is xmm, dst is mem
            $rn = $this->regNum($src->reg);
            $this->emit8(0xF2);
            $this->emitRex(false, $rn, $dst, 0);
            $this->emit8(0x0F);
            $this->emit8($storeOp);
            $this->emitModRM($rn, $dst);
        } else {
            // load: dst is xmm
            $rn = $this->regNum($dst->reg);
            $this->emit8(0xF2);
            $this->emitRex(false, $rn, $src, 0);
            $this->emit8(0x0F);
            $this->emit8($loadOp);
            $this->emitModRM($rn, $src);
        }
    }

    private function encodeSSEOp(int $prefix, int $opcode, array $ops): void
    {
        $src = $ops[0];
        $dst = $ops[1];
        $dstRn = $this->regNum($dst->reg);
        $this->emit8($prefix);
        $this->emitRex(false, $dstRn, $src, 0);
        $this->emit8(0x0F);
        $this->emit8($opcode);
        $this->emitModRM($dstRn, $src);
    }

    private function encodeCvtsi2sd(array $ops): void
    {
        $src = $ops[0];
        $dst = $ops[1];
        $dstRn = $this->regNum($dst->reg);
        $this->emit8(0xF2);
        $this->emitRex(true, $dstRn, $src, 8); // REX.W for 64-bit integer source
        $this->emit8(0x0F);
        $this->emit8(0x2A);
        $this->emitModRM($dstRn, $src);
    }

    private function encodeCvtsd2si(array $ops): void
    {
        $src = $ops[0];
        $dst = $ops[1];
        $dstRn = $this->regNum($dst->reg);
        $this->emit8(0xF2);
        $this->emitRex(true, $dstRn, $src, 8); // REX.W for 64-bit integer dest
        $this->emit8(0x0F);
        $this->emit8(0x2D);
        $this->emitModRM($dstRn, $src);
    }

    private function encodeMovqSSE(array $ops): void
    {
        $src = $ops[0];
        $dst = $ops[1];

        if ($src->kind === OperandKind::Register && str_starts_with($src->reg, 'xmm')) {
            // movq %xmm, %gp: 66 REX.W 0F 7E
            $xmmRn = $this->regNum($src->reg);
            $gpRn = $this->regNum($dst->reg);
            $this->emit8(0x66);
            $this->emit8(0x48 | (($xmmRn >> 3) << 2) | ($gpRn >> 3));
            $this->emit8(0x0F);
            $this->emit8(0x7E);
            $this->emit8(0xC0 | (($xmmRn & 7) << 3) | ($gpRn & 7));
        } else {
            // movq %gp, %xmm: 66 REX.W 0F 6E
            $gpRn = $this->regNum($src->reg);
            $xmmRn = $this->regNum($dst->reg);
            $this->emit8(0x66);
            $this->emit8(0x48 | (($xmmRn >> 3) << 2) | ($gpRn >> 3));
            $this->emit8(0x0F);
            $this->emit8(0x6E);
            $this->emit8(0xC0 | (($xmmRn & 7) << 3) | ($gpRn & 7));
        }
    }

    // ── Encoding helpers ────────────────────────────────────────────────────

    private function regNum(string $reg): int
    {
        return self::REG_NUM[$reg] ?? throw new \RuntimeException("Unknown register: {$reg}");
    }

    private function needsRexByte(string $reg): bool
    {
        return in_array($reg, ['spl', 'bpl', 'sil', 'dil'], true);
    }

    private function emitSizePrefix(int $size): void
    {
        if ($size === 2) {
            $this->emit8(0x66);
        }
    }

    /**
     * Emit REX prefix if needed.
     */
    private function emitRex(bool $w, int $regField, Operand $rm, int $size, ?string $regName = null): void
    {
        $r = ($regField >> 3) & 1;
        $x = 0;
        $b = 0;

        switch ($rm->kind) {
            case OperandKind::Register:
                $b = ($this->regNum($rm->reg) >> 3) & 1;
                break;
            case OperandKind::Memory:
                $b = ($this->regNum($rm->base) >> 3) & 1;
                break;
            case OperandKind::MemSib:
                $b = ($this->regNum($rm->base) >> 3) & 1;
                $x = ($this->regNum($rm->index) >> 3) & 1;
                break;
            default:
                // RipRel, Label, Immediate — no base/index extension
                break;
        }

        $rex = 0x40 | ($w ? 8 : 0) | ($r << 2) | ($x << 1) | $b;
        $needRex = $rex !== 0x40;

        // Byte operations with new byte registers (spl/bpl/sil/dil) need REX
        // even if all extension bits are 0, to distinguish from ah/ch/dh/bh.
        if ($size === 1 && !$needRex) {
            if ($rm->kind === OperandKind::Register && $this->needsRexByte($rm->reg)) {
                $needRex = true;
            }
            if ($regName !== null && $this->needsRexByte($regName)) {
                $needRex = true;
            }
        }

        if ($needRex) {
            $this->emit8($rex);
        }
    }

    /**
     * Emit ModRM (and SIB/displacement) for an r/m operand.
     */
    private function emitModRM(int $regField, Operand $rm): void
    {
        $reg3 = $regField & 7;

        if ($rm->kind === OperandKind::Register) {
            $rmNum = $this->regNum($rm->reg) & 7;
            $this->emit8(0xC0 | ($reg3 << 3) | $rmNum);
            return;
        }

        if ($rm->kind === OperandKind::RipRel) {
            // mod=00, rm=5 → RIP-relative with disp32
            $this->emit8(0x00 | ($reg3 << 3) | 5);
            $relocType = match ($rm->suffix) {
                'GOTPCREL' => 'GOTPCREL',
                'PLT' => 'PLT32',
                default => 'REL32',
            };
            $this->relocs[] = new Relocation(
                $this->sectionName,
                strlen($this->bytes),
                $relocType,
                $rm->label,
                $rm->disp,
            );
            $this->emitLE32(0);
            return;
        }

        if ($rm->kind === OperandKind::MemSib) {
            $this->emitMemSibModRM($reg3, $rm);
            return;
        }

        // Memory: disp(%base)
        $baseNum = $this->regNum($rm->base) & 7;

        // RSP/R12 (baseNum=4) needs SIB byte
        if ($baseNum === 4) {
            $disp = $rm->disp;
            $mod = $this->dispMod($disp, $baseNum);
            $this->emit8(($mod << 6) | ($reg3 << 3) | 4);
            $this->emit8(0x24); // SIB: scale=0, index=4(none), base=4(rsp)
            $this->emitDisp($mod, $disp);
            return;
        }

        // RBP/R13 (baseNum=5) with disp=0 needs mod=01 + disp8=0
        $disp = $rm->disp;
        $mod = $this->dispMod($disp, $baseNum);
        $this->emit8(($mod << 6) | ($reg3 << 3) | $baseNum);
        $this->emitDisp($mod, $disp);
    }

    private function emitMemSibModRM(int $reg3, Operand $op): void
    {
        $baseNum = $this->regNum($op->base) & 7;
        $indexNum = $this->regNum($op->index) & 7;
        $scaleEnc = match ($op->scale) {
            1 => 0, 2 => 1, 4 => 2, 8 => 3,
            default => throw new \RuntimeException("Invalid SIB scale: {$op->scale}"),
        };

        $disp = $op->disp;
        $mod = $this->dispMod($disp, $baseNum);

        $this->emit8(($mod << 6) | ($reg3 << 3) | 4); // rm=4 signals SIB
        $this->emit8(($scaleEnc << 6) | ($indexNum << 3) | $baseNum);
        $this->emitDisp($mod, $disp);
    }

    private function dispMod(int $disp, int $baseNum): int
    {
        if ($disp === 0 && $baseNum !== 5) {
            return 0b00;
        }
        if ($disp >= -128 && $disp <= 127) {
            return 0b01;
        }
        return 0b10;
    }

    private function emitDisp(int $mod, int $disp): void
    {
        if ($mod === 0b01) {
            $this->emit8($disp & 0xFF);
        } elseif ($mod === 0b10) {
            $this->emitLE32($disp);
        }
    }

    // ── Byte emission ───────────────────────────────────────────────────────

    private function emit8(int $b): void
    {
        $this->bytes .= chr($b & 0xFF);
    }

    private function emitBytes(array $bytes): void
    {
        foreach ($bytes as $b) {
            $this->bytes .= chr($b & 0xFF);
        }
    }

    private function emitLE16(int $v): void
    {
        $this->bytes .= pack('v', $v & 0xFFFF);
    }

    private function emitLE32(int $v): void
    {
        $this->bytes .= pack('V', $v & 0xFFFFFFFF);
    }

    private function emitLE64(int $v): void
    {
        $this->bytes .= pack('P', $v);
    }

    private function patchInt32(int $offset, int $v): void
    {
        $packed = pack('V', $v & 0xFFFFFFFF);
        $this->bytes[$offset] = $packed[0];
        $this->bytes[$offset + 1] = $packed[1];
        $this->bytes[$offset + 2] = $packed[2];
        $this->bytes[$offset + 3] = $packed[3];
    }
}
