<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class Parser
{
    /**
     * Parse GAS AT&T assembly text into per-section AsmLine arrays.
     *
     * @return array{sections: array<string, AsmLine[]>, globals: string[]}
     */
    public function parse(string $text): array
    {
        $sections = ['.text' => [], '.data' => [], '.rodata' => [], '.bss' => []];
        $current = '.text';
        $globals = [];

        foreach (explode("\n", $text) as $raw) {
            $line = $this->stripComment($raw);
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Label: identifier followed by colon at end of token
            if ($this->isLabel($line)) {
                $name = substr($line, 0, -1);
                $item = new AsmLine();
                $item->label = $name;
                $sections[$current][] = $item;
                continue;
            }

            // Directive
            if ($line[0] === '.') {
                $this->parseDirective($line, $sections, $current, $globals);
                continue;
            }

            // Instruction
            $item = $this->parseInstruction($line);
            $sections[$current][] = $item;
        }

        return ['sections' => $sections, 'globals' => $globals];
    }

    private function stripComment(string $line): string
    {
        $inQuote = false;
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            if ($ch === '"') {
                $inQuote = !$inQuote;
            } elseif ($ch === '\\' && $inQuote) {
                $i++; // skip escaped char
            } elseif ($ch === '#' && !$inQuote) {
                return substr($line, 0, $i);
            }
        }
        return $line;
    }

    private function isLabel(string $line): bool
    {
        if (!str_ends_with($line, ':')) {
            return false;
        }
        $name = substr($line, 0, -1);
        if ($name === '') {
            return false;
        }
        // Label names: start with letter, underscore, or dot
        $first = $name[0];
        if ($first !== '_' && $first !== '.' && !ctype_alpha($first)) {
            return false;
        }
        return true;
    }

    /**
     * @param array<string, AsmLine[]> $sections
     * @param string[] $globals
     */
    private function parseDirective(
        string $line,
        array &$sections,
        string &$current,
        array &$globals,
    ): void {
        // Extract directive name
        $pos = 0;
        $len = strlen($line);
        while ($pos < $len && $line[$pos] !== ' ' && $line[$pos] !== "\t" && $line[$pos] !== ',') {
            $pos++;
        }
        $directive = substr($line, 0, $pos);
        $rest = trim(substr($line, $pos));

        switch ($directive) {
            case '.text':
                $current = '.text';
                return;
            case '.data':
                $current = '.data';
                return;
            case '.rodata':
                $current = '.rodata';
                return;
            case '.bss':
                $current = '.bss';
                return;
            case '.section':
                $secName = trim($rest);
                // Handle ".section .rodata" etc.
                if (!isset($sections[$secName])) {
                    $sections[$secName] = [];
                }
                $current = $secName;
                return;
            case '.globl':
                $globals[] = trim($rest);
                return;
            case '.type':
                // .type funcname, @function → type_meta directive
                $parts = array_map('trim', explode(',', $rest, 2));
                if (count($parts) === 2) {
                    $typeName = ltrim($parts[1], '@');
                    $item = new AsmLine();
                    $item->directive = 'type_meta';
                    $item->directiveArgs = [$parts[0], $typeName];
                    $sections[$current][] = $item;
                }
                return;
            case '.size':
                // .size funcname, .-funcname → size_meta directive
                $parts = array_map('trim', explode(',', $rest, 2));
                if (count($parts) === 2) {
                    $item = new AsmLine();
                    $item->directive = 'size_meta';
                    $item->directiveArgs = [$parts[0], $parts[1]];
                    $sections[$current][] = $item;
                }
                return;
            case '.align':
                $item = new AsmLine();
                $item->directive = 'align';
                $item->directiveArgs = (int)$rest;
                $sections[$current][] = $item;
                return;
            case '.byte':
                $item = new AsmLine();
                $item->directive = 'byte';
                $item->directiveArgs = (int)$rest;
                $sections[$current][] = $item;
                return;
            case '.word':
                $item = new AsmLine();
                $item->directive = 'word';
                $item->directiveArgs = (int)$rest;
                $sections[$current][] = $item;
                return;
            case '.long':
                $item = new AsmLine();
                $item->directive = 'long';
                $item->directiveArgs = (int)$rest;
                $sections[$current][] = $item;
                return;
            case '.quad':
                $item = new AsmLine();
                $item->directive = 'quad';
                // Could be integer or symbol name
                if ($rest !== '' && ($rest[0] === '-' || ctype_digit($rest[0]))) {
                    $item->directiveArgs = $this->parseIntLiteral($rest);
                } else {
                    $item->directiveArgs = $rest; // symbol name
                }
                $sections[$current][] = $item;
                return;
            case '.asciz':
                $item = new AsmLine();
                $item->directive = 'asciz';
                $item->directiveArgs = $this->parseQuotedString($rest);
                $sections[$current][] = $item;
                return;
            case '.zero':
            case '.skip':
                $item = new AsmLine();
                $item->directive = 'zero';
                $item->directiveArgs = (int)$rest;
                $sections[$current][] = $item;
                return;
        }
    }

    private function parseInstruction(string $line): AsmLine
    {
        $item = new AsmLine();

        // Extract mnemonic (first whitespace-delimited token)
        $pos = 0;
        $len = strlen($line);
        while ($pos < $len && $line[$pos] !== ' ' && $line[$pos] !== "\t") {
            $pos++;
        }
        $item->mnemonic = substr($line, 0, $pos);
        $rest = trim(substr($line, $pos));

        if ($rest !== '') {
            $item->operands = $this->parseOperands($rest);
        }

        return $item;
    }

    /**
     * @return Operand[]
     */
    private function parseOperands(string $s): array
    {
        $operands = [];
        $parts = $this->splitOperands($s);
        foreach ($parts as $part) {
            $operands[] = $this->parseOperand(trim($part));
        }
        return $operands;
    }

    /**
     * Split on commas that are not inside parentheses.
     * @return string[]
     */
    private function splitOperands(string $s): array
    {
        $parts = [];
        $depth = 0;
        $start = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($ch === '(') {
                $depth++;
            } elseif ($ch === ')') {
                $depth--;
            } elseif ($ch === ',' && $depth === 0) {
                $parts[] = substr($s, $start, $i - $start);
                $start = $i + 1;
            }
        }
        $parts[] = substr($s, $start);
        return $parts;
    }

    private function parseOperand(string $s): Operand
    {
        if ($s === '') {
            throw new \RuntimeException('Empty operand');
        }

        // Indirect register: *%reg (used for indirect call/jmp)
        if ($s[0] === '*' && isset($s[1]) && $s[1] === '%') {
            return Operand::register(substr($s, 2));
        }

        // Register: %reg
        if ($s[0] === '%') {
            return Operand::register(substr($s, 1));
        }

        // Immediate: $val or $symbol_name
        if ($s[0] === '$') {
            $immStr = substr($s, 1);
            // If it starts with a letter or underscore, it's a symbol reference
            if ($immStr !== '' && ($immStr[0] === '_' || ctype_alpha($immStr[0]))) {
                return Operand::symbolImm($immStr);
            }
            return Operand::immediate($this->parseImmediateValue($immStr));
        }

        // Parenthesized form: [prefix](...)
        $parenPos = strpos($s, '(');
        if ($parenPos !== false) {
            $prefix = substr($s, 0, $parenPos);
            $closePos = strrpos($s, ')');
            $inner = substr($s, $parenPos + 1, $closePos - $parenPos - 1);

            $innerParts = explode(',', $inner);

            // RIP-relative: label(%rip) or label+offset(%rip) or label@GOTPCREL(%rip)
            if (count($innerParts) === 1 && trim($innerParts[0]) === '%rip') {
                $addend = 0;
                $label = $prefix;
                $suffix = '';

                // Check for @GOTPCREL suffix
                $atPos = strpos($label, '@');
                if ($atPos !== false) {
                    $suffix = substr($label, $atPos + 1);
                    $label = substr($label, 0, $atPos);
                }

                $plusPos = strpos($label, '+');
                if ($plusPos !== false) {
                    $addend = (int)substr($label, $plusPos + 1);
                    $label = substr($label, 0, $plusPos);
                }

                $op = Operand::ripRel($label, $addend);
                if ($suffix !== '') {
                    $op->suffix = $suffix;
                }
                return $op;
            }

            // Parse base register
            $base = trim($innerParts[0]);
            if ($base !== '' && $base[0] === '%') {
                $base = substr($base, 1);
            }

            // SIB form: (base, index, scale) or prefix(base, index, scale)
            if (count($innerParts) >= 3) {
                $index = trim($innerParts[1]);
                if ($index[0] === '%') {
                    $index = substr($index, 1);
                }
                $scale = (int)trim($innerParts[2]);
                $disp = ($prefix === '') ? 0 : (int)$prefix;
                return Operand::memSib($base, $index, $scale, $disp);
            }

            // Simple memory: disp(%base) or (%base)
            $disp = ($prefix === '') ? 0 : (int)$prefix;
            return Operand::memory($base, $disp);
        }

        // Bare label (for call/jmp targets), with optional @PLT suffix
        $suffix = '';
        $atPos = strpos($s, '@');
        if ($atPos !== false) {
            $suffix = substr($s, $atPos + 1);
            $s = substr($s, 0, $atPos);
        }
        $op = Operand::label($s);
        if ($suffix !== '') {
            $op->suffix = $suffix;
        }
        return $op;
    }

    private function parseImmediateValue(string $val): int
    {
        // Character: 'c'
        if (strlen($val) >= 3 && $val[0] === "'") {
            return ord($val[1]);
        }
        return $this->parseIntLiteral($val);
    }

    private function parseIntLiteral(string $val): int
    {
        $val = trim($val);
        $neg = false;
        if ($val !== '' && $val[0] === '-') {
            $neg = true;
            $val = substr($val, 1);
        }
        if (str_starts_with($val, '0x') || str_starts_with($val, '0X')) {
            $n = (int)hexdec(substr($val, 2));
        } else {
            $n = (int)$val;
        }
        return $neg ? -$n : $n;
    }

    private function parseQuotedString(string $s): string
    {
        // Strip surrounding quotes
        $s = trim($s);
        if ($s[0] === '"') {
            $s = substr($s, 1);
        }
        if (str_ends_with($s, '"')) {
            $s = substr($s, 0, -1);
        }

        $result = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            if ($s[$i] === '\\' && $i + 1 < $len) {
                $i++;
                $result .= match ($s[$i]) {
                    'n' => "\n",
                    'r' => "\r",
                    't' => "\t",
                    '0' => "\0",
                    '\\' => "\\",
                    '"' => '"',
                    default => $this->parseOctalEscape($s, $i),
                };
            } else {
                $result .= $s[$i];
            }
        }
        return $result;
    }

    private function parseOctalEscape(string $s, int &$i): string
    {
        // Already at first digit after backslash
        $digits = $s[$i];
        while ($i + 1 < strlen($s) && $s[$i + 1] >= '0' && $s[$i + 1] <= '7' && strlen($digits) < 3) {
            $i++;
            $digits .= $s[$i];
        }
        return chr((int)octdec($digits));
    }
}
