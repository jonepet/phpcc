<?php

declare(strict_types=1);

namespace Cppc\CodeGen;

/**
 * Emits GAS (GNU Assembler) AT&T-syntax x86-64 assembly text.
 *
 * Operand convention (AT&T):  source, destination
 * Register names are prefixed with %:  %rax, %xmm0
 * Immediates are prefixed with $:      $42
 * Memory operands:                     -8(%rbp), (%rax), (%rax,%rbx,4)
 */
class AsmEmitter
{
    private string $output = '';
    private int $indentLevel = 0;

    public function getOutput(): string
    {
        return $this->output;
    }

    public function reset(): void
    {
        $this->output     = '';
        $this->indentLevel = 0;
    }

    public function indent(): void
    {
        $this->indentLevel++;
    }

    public function dedent(): void
    {
        if ($this->indentLevel > 0) {
            $this->indentLevel--;
        }
    }

    private function line(string $text): void
    {
        $this->output .= str_repeat('    ', $this->indentLevel) . $text . "\n";
    }

    public function section(string $name): void
    {
        if (in_array($name, ['.text', '.data', '.bss'], true)) {
            $this->line($name);
        } else {
            $this->line('.section ' . $name);
        }
    }

    public function text(): void    { $this->section('.text'); }
    public function data(): void    { $this->section('.data'); }
    public function rodata(): void  { $this->section('.rodata'); }
    public function bss(): void     { $this->section('.bss'); }

    public function global(string $name): void
    {
        $this->line('.globl ' . $name);
    }

    public function label(string $name): void
    {
        // Labels always start at column 0.
        $saved = $this->indentLevel;
        $this->indentLevel = 0;
        $this->line($name . ':');
        $this->indentLevel = $saved;
    }

    public function align(int $n): void
    {
        $this->line('.align ' . $n);
    }

    public function byte(int $val): void
    {
        $this->line('.byte ' . $val);
    }

    public function word(int $val): void
    {
        $this->line('.word ' . $val);
    }

    public function long(int $val): void
    {
        $this->line('.long ' . $val);
    }

    public function quad(int|string $val): void
    {
        $this->line('.quad ' . $val);
    }

    /**
     * `.asciz "escaped"` — the string is C-escaped so special bytes survive.
     */
    public function asciz(string $val): void
    {
        $this->line('.asciz "' . $this->escapeString($val) . '"');
    }

    public function zero(int $bytes): void
    {
        $this->line('.zero ' . $bytes);
    }

    public function type(string $name, string $type): void
    {
        $this->line('.type ' . $name . ', @' . $type);
    }

    public function size(string $name, string $expr): void
    {
        $this->line('.size ' . $name . ', ' . $expr);
    }

    public function comment(string $text): void
    {
        $this->line('# ' . $text);
    }

    public function blank(): void
    {
        $this->output .= "\n";
    }

    public function emit(string $mnemonic, string ...$operands): void
    {
        $saved = $this->indentLevel;
        $this->indentLevel = 1;   // instructions always at one level of indent
        if ($operands !== []) {
            $this->line($mnemonic . ' ' . implode(', ', $operands));
        } else {
            $this->line($mnemonic);
        }
        $this->indentLevel = $saved;
    }

    public function mov(string $src, string $dst): void
    {
        $srcXmm = str_contains($src, 'xmm');
        $dstXmm = str_contains($dst, 'xmm');
        if ($srcXmm || $dstXmm) {
            if ($srcXmm && $dstXmm) {
                $this->emit('movsd', $src, $dst);
            } else {
                $this->emit('movq', $src, $dst);
            }
        } else {
            $this->emit('mov', $src, $dst);
        }
    }
    public function add(string $src, string $dst): void     { $this->emit('add', $src, $dst); }
    public function sub(string $src, string $dst): void     { $this->emit('sub', $src, $dst); }
    public function imul(string $src, string $dst): void    { $this->emit('imul', $src, $dst); }
    public function push(string $op): void                  { $this->emit('push', $op); }
    public function pop(string $op): void                   { $this->emit('pop', $op); }
    public function call(string $target): void              { $this->emit('call', $target); }
    public function ret(): void                             { $this->emit('ret'); }
    public function jmp(string $label): void                { $this->emit('jmp', $label); }
    public function je(string $label): void                 { $this->emit('je', $label); }
    public function jne(string $label): void                { $this->emit('jne', $label); }
    public function jl(string $label): void                 { $this->emit('jl', $label); }
    public function jle(string $label): void                { $this->emit('jle', $label); }
    public function jg(string $label): void                 { $this->emit('jg', $label); }
    public function jge(string $label): void                { $this->emit('jge', $label); }
    public function cmp(string $src, string $dst): void     { $this->emit('cmp', $src, $dst); }
    public function test(string $a, string $b): void        { $this->emit('test', $a, $b); }
    public function lea(string $mem, string $dst): void     { $this->emit('lea', $mem, $dst); }

    public function xor_(string $src, string $dst): void    { $this->emit('xor', $src, $dst); }
    public function and_(string $src, string $dst): void    { $this->emit('and', $src, $dst); }
    public function or_(string $src, string $dst): void     { $this->emit('or', $src, $dst); }
    public function shl(string $cnt, string $dst): void     { $this->emit('shl', $cnt, $dst); }
    public function shr(string $cnt, string $dst): void     { $this->emit('shr', $cnt, $dst); }
    public function sar(string $cnt, string $dst): void     { $this->emit('sar', $cnt, $dst); }
    public function neg(string $op): void                   { $this->emit('neg', $op); }
    public function not_(string $op): void                  { $this->emit('not', $op); }

    /** Sign-extend eax → edx:eax (for 32-bit idiv) */
    public function cdq(): void                             { $this->emit('cdq'); }
    /** Sign-extend rax → rdx:rax (for 64-bit idiv) */
    public function cqo(): void                             { $this->emit('cqo'); }

    public function idiv(string $op): void                  { $this->emit('idiv', $op); }
    public function div_(string $op): void                  { $this->emit('div', $op); }

    public function movsx(string $src, string $dst): void
    {
        $srcSize = self::regOperandSize($src);
        $dstSize = self::regOperandSize($dst);
        if ($srcSize >= $dstSize) {
            // Same size or truncation — plain move, no sign-extend needed.
            $this->emit('movq', $src, $dst);
        } elseif ($srcSize === 4 && $dstSize === 8) {
            $this->emit('movslq', $src, $dst);
        } else {
            $mn = 'movs' . self::sizeSuffix($srcSize) . self::sizeSuffix($dstSize);
            $this->emit($mn, $src, $dst);
        }
    }

    public function movzx(string $src, string $dst): void
    {
        $srcSize = self::regOperandSize($src);
        $dstSize = self::regOperandSize($dst);
        if ($srcSize >= $dstSize) {
            // Same size or truncation — plain move, no zero-extend needed.
            $this->emit('movq', $src, $dst);
        } elseif ($srcSize === 4 && $dstSize === 8) {
            // Zero-extending 32→64 is automatic in x86-64: use movl to 32-bit sub-register
            $dst32 = '%' . self::reg32(ltrim($dst, '%'));
            $this->emit('movl', $src, $dst32);
        } else {
            $mn = 'movz' . self::sizeSuffix($srcSize) . self::sizeSuffix($dstSize);
            $this->emit($mn, $src, $dst);
        }
    }

    private static function reg32(string $reg64): string
    {
        return match ($reg64) {
            'rax' => 'eax', 'rbx' => 'ebx', 'rcx' => 'ecx', 'rdx' => 'edx',
            'rsp' => 'esp', 'rbp' => 'ebp', 'rsi' => 'esi', 'rdi' => 'edi',
            'r8' => 'r8d', 'r9' => 'r9d', 'r10' => 'r10d', 'r11' => 'r11d',
            'r12' => 'r12d', 'r13' => 'r13d', 'r14' => 'r14d', 'r15' => 'r15d',
            default => $reg64,
        };
    }

    private static function regOperandSize(string $op): int
    {
        $reg = ltrim($op, '%');
        if (in_array($reg, ['al','bl','cl','dl','spl','bpl','sil','dil',
                             'r8b','r9b','r10b','r11b','r12b','r13b','r14b','r15b'], true)) return 1;
        if (in_array($reg, ['ax','bx','cx','dx','sp','bp','si','di',
                             'r8w','r9w','r10w','r11w','r12w','r13w','r14w','r15w'], true)) return 2;
        if (in_array($reg, ['eax','ebx','ecx','edx','esp','ebp','esi','edi',
                             'r8d','r9d','r10d','r11d','r12d','r13d','r14d','r15d'], true)) return 4;
        return 8;
    }

    public function sete(string $dst): void                 { $this->emit('sete', $dst); }
    public function setne(string $dst): void                { $this->emit('setne', $dst); }
    public function setl(string $dst): void                 { $this->emit('setl', $dst); }
    public function setle(string $dst): void                { $this->emit('setle', $dst); }
    public function setg(string $dst): void                 { $this->emit('setg', $dst); }
    public function setge(string $dst): void                { $this->emit('setge', $dst); }

    public function movss(string $src, string $dst): void       { $this->emit('movss', $src, $dst); }
    public function movsd(string $src, string $dst): void       { $this->emit('movsd', $src, $dst); }
    public function addss(string $src, string $dst): void       { $this->emit('addss', $src, $dst); }
    public function addsd(string $src, string $dst): void       { $this->emit('addsd', $src, $dst); }
    public function subss(string $src, string $dst): void       { $this->emit('subss', $src, $dst); }
    public function subsd(string $src, string $dst): void       { $this->emit('subsd', $src, $dst); }
    public function mulss(string $src, string $dst): void       { $this->emit('mulss', $src, $dst); }
    public function mulsd(string $src, string $dst): void       { $this->emit('mulsd', $src, $dst); }
    public function divss(string $src, string $dst): void       { $this->emit('divss', $src, $dst); }
    public function divsd(string $src, string $dst): void       { $this->emit('divsd', $src, $dst); }

    public function cvtsi2ss(string $src, string $dst): void    { $this->emit('cvtsi2ss', $src, $dst); }
    public function cvtsi2sd(string $src, string $dst): void    { $this->emit('cvtsi2sd', $src, $dst); }
    public function cvtss2si(string $src, string $dst): void    { $this->emit('cvtss2si', $src, $dst); }
    public function cvtsd2si(string $src, string $dst): void    { $this->emit('cvtsd2si', $src, $dst); }
    public function cvtss2sd(string $src, string $dst): void    { $this->emit('cvtss2sd', $src, $dst); }
    public function cvtsd2ss(string $src, string $dst): void    { $this->emit('cvtsd2ss', $src, $dst); }

    public function ucomiss(string $src, string $dst): void     { $this->emit('ucomiss', $src, $dst); }
    public function ucomisd(string $src, string $dst): void     { $this->emit('ucomisd', $src, $dst); }

    public function reg(string $name): string
    {
        return '%' . $name;
    }

    public function imm(int $val): string
    {
        return '$' . $val;
    }

    /**
     * Format a memory operand.
     *
     * With offset:     -8(%rbp)
     * Without offset:  (%rax)
     */
    public function mem(string $base, int $offset = 0): string
    {
        if ($offset === 0) {
            return '(%' . $base . ')';
        }
        return $offset . '(%' . $base . ')';
    }

    /**
     * Format an indexed memory operand: (%rax,%rbx,4)
     * Useful for array element access.
     */
    public function memIdx(string $base, string $index, int $scale): string
    {
        return '(%' . $base . ',%' . $index . ',' . $scale . ')';
    }

    /**
     * Format a RIP-relative memory reference: label(%rip)
     * Used for accessing global/static data in position-independent code.
     */
    public function ripRel(string $label): string
    {
        return $label . '(%rip)';
    }

    /**
     * Returns the GAS mnemonic suffix for the given operand size.
     * 1→'b', 2→'w', 4→'l', 8→'q'
     */
    public static function sizeSuffix(int $size): string
    {
        return match ($size) {
            1       => 'b',
            2       => 'w',
            4       => 'l',
            default => 'q',
        };
    }

    private function escapeString(string $s): string
    {
        $result = '';
        $len    = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $result .= match ($c) {
                "\\"    => '\\\\',
                "\""    => '\\"',
                "\n"    => '\\n',
                "\r"    => '\\r',
                "\t"    => '\\t',
                "\0"    => '\\0',
                default => (ord($c) < 32 || ord($c) > 126)
                    ? sprintf('\\%03o', ord($c))
                    : $c,
            };
        }

        return $result;
    }
}
