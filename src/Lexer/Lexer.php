<?php

declare(strict_types=1);

namespace Cppc\Lexer;

use Cppc\CompileError;

class Lexer
{
    private string $source = '';
    private int    $pos    = 0;
    private int    $len    = 0;
    private int    $line   = 1;
    private int    $col    = 1;
    private string $file   = '';
    /** @var Token[] */
    private array $tokens = [];

    public function tokenize(string $source, string $file = ''): array
    {
        $this->source = $source;
        $this->pos    = 0;
        $this->len    = strlen($source);
        $this->line   = 1;
        $this->col    = 1;
        $this->file   = $file;
        $this->tokens = [];

        while ($this->pos < $this->len) {
            $this->scanToken();
        }

        $this->tokens[] = new Token(TokenType::EOF, '', $this->line, $this->col, $this->file);

        return $this->tokens;
    }

    private function scanToken(): void
    {
        $startLine = $this->line;
        $startCol  = $this->col;
        $ch        = $this->advance();

        if ($ch === ' ' || $ch === "\t" || $ch === "\r" || $ch === "\n" || $ch === "\f" || $ch === "\v") {
            return;
        }

        if ($ch === '/' && $this->peek() === '/') {
            $this->advance();
            while ($this->pos < $this->len && $this->current() !== "\n") {
                $this->advance();
            }
            return;
        }

        if ($ch === '/' && $this->peek() === '*') {
            $this->advance(); // consume '*'
            while (true) {
                if ($this->pos >= $this->len) {
                    throw new CompileError('Unterminated block comment', $this->file, $startLine, $startCol);
                }
                $c = $this->advance();
                if ($c === '*' && $this->peek() === '/') {
                    $this->advance(); // consume '/'
                    break;
                }
            }
            return;
        }

        if ($ch === '"') {
            $this->scanString($startLine, $startCol);
            return;
        }

        if ($ch === '\'') {
            $this->scanChar($startLine, $startCol);
            return;
        }

        if ($this->isDigit($ch)) {
            $this->scanNumber($ch, $startLine, $startCol);
            return;
        }

        if ($this->isIdentStart($ch)) {
            $this->scanIdentifier($ch, $startLine, $startCol);
            return;
        }

        $this->scanOperator($ch, $startLine, $startCol);
    }

    private function scanString(int $startLine, int $startCol): void
    {
        $value = '';
        while (true) {
            if ($this->pos >= $this->len) {
                throw new CompileError('Unterminated string literal', $this->file, $startLine, $startCol);
            }
            $ch = $this->advance();
            if ($ch === '"') {
                break;
            }
            if ($ch === "\n") {
                throw new CompileError('Unterminated string literal (newline)', $this->file, $startLine, $startCol);
            }
            if ($ch === '\\') {
                $value .= $this->scanEscape($startLine, $startCol);
            } else {
                $value .= $ch;
            }
        }
        $this->tokens[] = new Token(TokenType::StringLiteral, $value, $startLine, $startCol, $this->file);
    }

    private function scanChar(int $startLine, int $startCol): void
    {
        if ($this->pos >= $this->len) {
            throw new CompileError('Unterminated char literal', $this->file, $startLine, $startCol);
        }

        $ch = $this->advance();
        if ($ch === '\\') {
            $value = $this->scanEscape($startLine, $startCol);
        } elseif ($ch === '\'') {
            throw new CompileError('Empty char literal', $this->file, $startLine, $startCol);
        } else {
            $value = $ch;
        }

        if ($this->pos >= $this->len || $this->advance() !== '\'') {
            throw new CompileError('Unterminated char literal', $this->file, $startLine, $startCol);
        }

        $this->tokens[] = new Token(TokenType::CharLiteral, $value, $startLine, $startCol, $this->file);
    }

    /**
     * Parse an escape sequence after the leading backslash has been consumed.
     * Returns the interpreted single character (or multi-byte for Unicode, but
     * we keep it simple: return the raw string representation).
     */
    private function scanEscape(int $startLine, int $startCol): string
    {
        if ($this->pos >= $this->len) {
            throw new CompileError('Unterminated escape sequence', $this->file, $startLine, $startCol);
        }
        $esc = $this->advance();
        return match ($esc) {
            'n'  => "\n",
            't'  => "\t",
            'r'  => "\r",
            '\\' => '\\',
            '"'  => '"',
            '\'' => '\'',
            '0'  => $this->scanOctalEscape('0', $startLine, $startCol),
            'x'  => $this->scanHexEscape($startLine, $startCol),
            'a'  => "\x07",
            'b'  => "\x08",
            'f'  => "\x0C",
            'v'  => "\x0B",
            '?'  => '?',
            '1', '2', '3', '4', '5', '6', '7'
                 => $this->scanOctalEscape($esc, $startLine, $startCol),
            default => throw new CompileError(
                "Unknown escape sequence '\\{$esc}'",
                $this->file,
                $startLine,
                $startCol,
            ),
        };
    }

    /**
     * Parse up to two more octal digits (first digit already consumed, passed as $first).
     */
    private function scanOctalEscape(string $first, int $startLine, int $startCol): string
    {
        $digits = $first;
        for ($i = 0; $i < 2; $i++) {
            if ($this->pos < $this->len && $this->isOctalDigit($this->current())) {
                $digits .= $this->advance();
            } else {
                break;
            }
        }
        $code = octdec($digits);
        if ($code > 255) {
            throw new CompileError(
                "Octal escape sequence out of range: \\{$digits}",
                $this->file,
                $startLine,
                $startCol,
            );
        }
        return chr((int) $code);
    }

    /**
     * Parse exactly two hex digits after \x.
     */
    private function scanHexEscape(int $startLine, int $startCol): string
    {
        $digits = '';
        for ($i = 0; $i < 2; $i++) {
            if ($this->pos >= $this->len || !$this->isHexDigit($this->current())) {
                throw new CompileError(
                    'Invalid \\x escape: expected two hex digits',
                    $this->file,
                    $startLine,
                    $startCol,
                );
            }
            $digits .= $this->advance();
        }
        return chr((int) hexdec($digits));
    }

    private function scanNumber(string $first, int $startLine, int $startCol): void
    {
        if ($first === '0' && $this->pos < $this->len) {
            $next = $this->current();

            if ($next === 'x' || $next === 'X') {
                $this->advance(); // consume 'x'
                $raw = '0x';
                if ($this->pos >= $this->len || !$this->isHexDigit($this->current())) {
                    throw new CompileError(
                        'Invalid hex literal: no digits after 0x',
                        $this->file,
                        $startLine,
                        $startCol,
                    );
                }
                while ($this->pos < $this->len && $this->isHexDigit($this->current())) {
                    $raw .= $this->advance();
                }
                $this->consumeIntSuffix();
                $this->tokens[] = new Token(TokenType::IntLiteral, $raw, $startLine, $startCol, $this->file);
                return;
            }

            if ($next === 'b' || $next === 'B') {
                $this->advance(); // consume 'b'
                $raw = '0b';
                if ($this->pos >= $this->len || !$this->isBinaryDigit($this->current())) {
                    throw new CompileError(
                        'Invalid binary literal: no digits after 0b',
                        $this->file,
                        $startLine,
                        $startCol,
                    );
                }
                while ($this->pos < $this->len && $this->isBinaryDigit($this->current())) {
                    $raw .= $this->advance();
                }
                $this->consumeIntSuffix();
                $this->tokens[] = new Token(TokenType::IntLiteral, $raw, $startLine, $startCol, $this->file);
                return;
            }

            // Fall through for octal or float starting with 0
        }

        $raw     = $first;
        $isOctal = ($first === '0');

        while ($this->pos < $this->len && $this->isDigit($this->current())) {
            $ch = $this->advance();
            $raw .= $ch;
            if (!$this->isOctalDigit($ch) && $ch !== '8' && $ch !== '9') {
                // still digits but we track whether it could be octal
            }
        }

        $isFloat = false;

        if ($this->pos < $this->len && $this->current() === '.') {
            // A dot following digits is a decimal point, not member access or ellipsis.
            $isFloat = true;
            $raw .= $this->advance();
            while ($this->pos < $this->len && $this->isDigit($this->current())) {
                $raw .= $this->advance();
            }
        }

        if ($this->pos < $this->len && ($this->current() === 'e' || $this->current() === 'E')) {
            $isFloat = true;
            $raw .= $this->advance(); // consume 'e'/'E'
            if ($this->pos < $this->len && ($this->current() === '+' || $this->current() === '-')) {
                $raw .= $this->advance(); // consume sign
            }
            if ($this->pos >= $this->len || !$this->isDigit($this->current())) {
                throw new CompileError(
                    'Invalid float literal: expected digits after exponent',
                    $this->file,
                    $startLine,
                    $startCol,
                );
            }
            while ($this->pos < $this->len && $this->isDigit($this->current())) {
                $raw .= $this->advance();
            }
        }

        if ($isFloat) {
            if ($this->pos < $this->len) {
                $s = $this->current();
                if ($s === 'f' || $s === 'F' || $s === 'l' || $s === 'L') {
                    $this->advance();
                }
            }
            $this->tokens[] = new Token(TokenType::FloatLiteral, $raw, $startLine, $startCol, $this->file);
        } else {
            if ($isOctal && strlen($raw) > 1) {
                for ($i = 1; $i < strlen($raw); $i++) {
                    if ($raw[$i] === '8' || $raw[$i] === '9') {
                        throw new CompileError(
                            "Invalid octal literal '{$raw}': digit '{$raw[$i]}' out of range",
                            $this->file,
                            $startLine,
                            $startCol,
                        );
                    }
                }
            }
            $this->consumeIntSuffix();
            $this->tokens[] = new Token(TokenType::IntLiteral, $raw, $startLine, $startCol, $this->file);
        }
    }

    /** Consume optional integer suffixes: u, U, l, L, ll, LL, ul, lu, etc. */
    private function consumeIntSuffix(): void
    {
        $remaining = 3;
        while ($remaining > 0 && $this->pos < $this->len) {
            $c = $this->current();
            if ($c === 'u' || $c === 'U' || $c === 'l' || $c === 'L') {
                $this->advance();
                $remaining--;
            } else {
                break;
            }
        }
    }

    private function scanIdentifier(string $first, int $startLine, int $startCol): void
    {
        $ident = $first;
        while ($this->pos < $this->len && $this->isIdentContinue($this->current())) {
            $ident .= $this->advance();
        }

        $type = Token::$keywords[$ident] ?? TokenType::Identifier;
        $this->tokens[] = new Token($type, $ident, $startLine, $startCol, $this->file);
    }

    private function scanOperator(string $ch, int $startLine, int $startCol): void
    {
        $peek1 = $this->peek();
        $peek2 = $this->peekAt(1);

        switch ($ch) {
            case '.':
                if ($peek1 === '.' && $peek2 === '.') {
                    $this->advance();
                    $this->advance();
                    $this->emit(TokenType::Ellipsis, '...', $startLine, $startCol);
                } elseif ($peek1 === '*') {
                    $this->advance(); // consume '*'
                    $this->emit(TokenType::DotStar, '.*', $startLine, $startCol);
                } elseif ($this->isDigit($peek1)) {
                    // Float like .5 — number starting with dot
                    $this->scanFloatFromDot($startLine, $startCol);
                } else {
                    $this->emit(TokenType::Dot, '.', $startLine, $startCol);
                }
                break;

            case '+':
                if ($peek1 === '+') {
                    $this->advance();
                    $this->emit(TokenType::PlusPlus, '++', $startLine, $startCol);
                } elseif ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::PlusAssign, '+=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Plus, '+', $startLine, $startCol);
                }
                break;

            case '-':
                if ($peek1 === '-') {
                    $this->advance();
                    $this->emit(TokenType::MinusMinus, '--', $startLine, $startCol);
                } elseif ($peek1 === '>' && $peek2 === '*') {
                    $this->advance(); // consume '>'
                    $this->advance(); // consume '*'
                    $this->emit(TokenType::ArrowStar, '->*', $startLine, $startCol);
                } elseif ($peek1 === '>') {
                    $this->advance();
                    $this->emit(TokenType::Arrow, '->', $startLine, $startCol);
                } elseif ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::MinusAssign, '-=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Minus, '-', $startLine, $startCol);
                }
                break;

            case '*':
                if ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::StarAssign, '*=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Star, '*', $startLine, $startCol);
                }
                break;

            case '/':
                // Comments were handled earlier; reaching here means plain '/' or '/='
                if ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::SlashAssign, '/=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Slash, '/', $startLine, $startCol);
                }
                break;

            case '%':
                if ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::PercentAssign, '%=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Percent, '%', $startLine, $startCol);
                }
                break;

            case '&':
                if ($peek1 === '&') {
                    $this->advance();
                    $this->emit(TokenType::LogicalAnd, '&&', $startLine, $startCol);
                } elseif ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::AmpersandAssign, '&=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Ampersand, '&', $startLine, $startCol);
                }
                break;

            case '|':
                if ($peek1 === '|') {
                    $this->advance();
                    $this->emit(TokenType::LogicalOr, '||', $startLine, $startCol);
                } elseif ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::PipeAssign, '|=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Pipe, '|', $startLine, $startCol);
                }
                break;

            case '^':
                if ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::CaretAssign, '^=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Caret, '^', $startLine, $startCol);
                }
                break;

            case '~':
                $this->emit(TokenType::Tilde, '~', $startLine, $startCol);
                break;

            case '!':
                if ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::NotEqual, '!=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Exclamation, '!', $startLine, $startCol);
                }
                break;

            case '=':
                if ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::Equal, '==', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Assign, '=', $startLine, $startCol);
                }
                break;

            case '<':
                if ($peek1 === '<') {
                    $this->advance(); // consume second '<'
                    if ($this->peek() === '=') {
                        $this->advance(); // consume '='
                        $this->emit(TokenType::ShiftLeftAssign, '<<=', $startLine, $startCol);
                    } else {
                        $this->emit(TokenType::ShiftLeft, '<<', $startLine, $startCol);
                    }
                } elseif ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::LessEqual, '<=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Less, '<', $startLine, $startCol);
                }
                break;

            case '>':
                if ($peek1 === '>') {
                    $this->advance(); // consume second '>'
                    if ($this->peek() === '=') {
                        $this->advance(); // consume '='
                        $this->emit(TokenType::ShiftRightAssign, '>>=', $startLine, $startCol);
                    } else {
                        $this->emit(TokenType::ShiftRight, '>>', $startLine, $startCol);
                    }
                } elseif ($peek1 === '=') {
                    $this->advance();
                    $this->emit(TokenType::GreaterEqual, '>=', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Greater, '>', $startLine, $startCol);
                }
                break;

            case ':':
                if ($peek1 === ':') {
                    $this->advance();
                    $this->emit(TokenType::DoubleColon, '::', $startLine, $startCol);
                } else {
                    $this->emit(TokenType::Colon, ':', $startLine, $startCol);
                }
                break;

            case ';':
                $this->emit(TokenType::Semicolon, ';', $startLine, $startCol);
                break;
            case ',':
                $this->emit(TokenType::Comma, ',', $startLine, $startCol);
                break;
            case '?':
                $this->emit(TokenType::Question, '?', $startLine, $startCol);
                break;
            case '(':
                $this->emit(TokenType::LeftParen, '(', $startLine, $startCol);
                break;
            case ')':
                $this->emit(TokenType::RightParen, ')', $startLine, $startCol);
                break;
            case '{':
                $this->emit(TokenType::LeftBrace, '{', $startLine, $startCol);
                break;
            case '}':
                $this->emit(TokenType::RightBrace, '}', $startLine, $startCol);
                break;
            case '[':
                $this->emit(TokenType::LeftBracket, '[', $startLine, $startCol);
                break;
            case ']':
                $this->emit(TokenType::RightBracket, ']', $startLine, $startCol);
                break;

            default:
                throw new CompileError(
                    "Unexpected character '{$ch}'",
                    $this->file,
                    $startLine,
                    $startCol,
                );
        }
    }

    /**
     * Handle a float literal that starts with '.' (e.g. .5, .5e3).
     * Called after '.' was consumed from the operator path.
     */
    private function scanFloatFromDot(int $startLine, int $startCol): void
    {
        $raw = '.';
        while ($this->pos < $this->len && $this->isDigit($this->current())) {
            $raw .= $this->advance();
        }
        if ($this->pos < $this->len && ($this->current() === 'e' || $this->current() === 'E')) {
            $raw .= $this->advance();
            if ($this->pos < $this->len && ($this->current() === '+' || $this->current() === '-')) {
                $raw .= $this->advance();
            }
            if ($this->pos >= $this->len || !$this->isDigit($this->current())) {
                throw new CompileError(
                    'Invalid float literal: expected digits after exponent',
                    $this->file,
                    $startLine,
                    $startCol,
                );
            }
            while ($this->pos < $this->len && $this->isDigit($this->current())) {
                $raw .= $this->advance();
            }
        }
        if ($this->pos < $this->len) {
            $s = $this->current();
            if ($s === 'f' || $s === 'F' || $s === 'l' || $s === 'L') {
                $this->advance();
            }
        }
        $this->tokens[] = new Token(TokenType::FloatLiteral, $raw, $startLine, $startCol, $this->file);
    }

    private function advance(): string
    {
        $ch = $this->source[$this->pos];
        $this->pos++;
        if ($ch === "\n") {
            $this->line++;
            $this->col = 1;
        } else {
            $this->col++;
        }
        return $ch;
    }

    private function current(): string
    {
        return $this->source[$this->pos] ?? '';
    }

    private function peek(): string
    {
        return $this->source[$this->pos] ?? '';
    }

    /** Return the character n+1 positions ahead of current position (0-indexed from current). */
    private function peekAt(int $offset): string
    {
        return $this->source[$this->pos + $offset] ?? '';
    }

    private function emit(TokenType $type, string $value, int $line, int $col): void
    {
        $this->tokens[] = new Token($type, $value, $line, $col, $this->file);
    }

    private function isDigit(string $ch): bool
    {
        return $ch >= '0' && $ch <= '9';
    }

    private function isHexDigit(string $ch): bool
    {
        return ($ch >= '0' && $ch <= '9')
            || ($ch >= 'a' && $ch <= 'f')
            || ($ch >= 'A' && $ch <= 'F');
    }

    private function isOctalDigit(string $ch): bool
    {
        return $ch >= '0' && $ch <= '7';
    }

    private function isBinaryDigit(string $ch): bool
    {
        return $ch === '0' || $ch === '1';
    }

    private function isIdentStart(string $ch): bool
    {
        return ($ch >= 'a' && $ch <= 'z')
            || ($ch >= 'A' && $ch <= 'Z')
            || $ch === '_';
    }

    private function isIdentContinue(string $ch): bool
    {
        return $this->isIdentStart($ch) || $this->isDigit($ch);
    }
}
