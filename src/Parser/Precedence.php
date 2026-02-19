<?php

declare(strict_types=1);

namespace Cppc\Parser;

use Cppc\Lexer\TokenType;

/**
 * Operator precedence table for Pratt parsing.
 *
 * Levels mirror C++ standard precedence (higher = tighter binding):
 *   2  — assignment (right-assoc)
 *   3  — ternary ?:
 *   4  — logical OR  ||
 *   5  — logical AND &&
 *   6  — bitwise OR  |
 *   7  — bitwise XOR ^
 *   8  — bitwise AND &
 *   9  — equality    == !=
 *  10  — relational  < > <= >=
 *  11  — shift       << >>
 *  12  — additive    + -
 *  13  — multiplicative * / %
 *  14  — prefix unary  (not used here; handled in parsePrefixExpression)
 *  15  — postfix      ++ -- ( [ . ->
 *  16  — scope        ::
 */
final class Precedence
{
    public const int NONE           = 0;
    public const int COMMA          = 1;
    public const int ASSIGNMENT     = 2;
    public const int TERNARY        = 3;
    public const int LOGICAL_OR     = 4;
    public const int LOGICAL_AND    = 5;
    public const int BITWISE_OR     = 6;
    public const int BITWISE_XOR    = 7;
    public const int BITWISE_AND    = 8;
    public const int EQUALITY       = 9;
    public const int RELATIONAL     = 10;
    public const int SHIFT          = 11;
    public const int ADDITIVE       = 12;
    public const int MULTIPLICATIVE = 13;
    public const int PREFIX         = 14;
    public const int POSTFIX        = 15;
    public const int SCOPE          = 16;

    public static function getInfixPrecedence(TokenType $type): int
    {
        return match ($type) {
            // Assignment operators — right-associative, so we consume with a
            // slightly lower precedence inside parseInfixExpression.
            TokenType::Assign,
            TokenType::PlusAssign,
            TokenType::MinusAssign,
            TokenType::StarAssign,
            TokenType::SlashAssign,
            TokenType::PercentAssign,
            TokenType::AmpersandAssign,
            TokenType::PipeAssign,
            TokenType::CaretAssign,
            TokenType::ShiftLeftAssign,
            TokenType::ShiftRightAssign   => self::ASSIGNMENT,

            TokenType::Question           => self::TERNARY,

            TokenType::LogicalOr          => self::LOGICAL_OR,
            TokenType::LogicalAnd         => self::LOGICAL_AND,

            TokenType::Pipe               => self::BITWISE_OR,
            TokenType::Caret              => self::BITWISE_XOR,
            TokenType::Ampersand          => self::BITWISE_AND,

            TokenType::Equal,
            TokenType::NotEqual           => self::EQUALITY,

            TokenType::Less,
            TokenType::Greater,
            TokenType::LessEqual,
            TokenType::GreaterEqual       => self::RELATIONAL,

            TokenType::ShiftLeft,
            TokenType::ShiftRight         => self::SHIFT,

            TokenType::Plus,
            TokenType::Minus              => self::ADDITIVE,

            TokenType::Star,
            TokenType::Slash,
            TokenType::Percent            => self::MULTIPLICATIVE,

            TokenType::PlusPlus,
            TokenType::MinusMinus,
            TokenType::LeftParen,
            TokenType::LeftBracket,
            TokenType::Dot,
            TokenType::Arrow              => self::POSTFIX,

            TokenType::DoubleColon        => self::SCOPE,

            TokenType::Comma              => self::COMMA,

            default                       => self::NONE,
        };
    }

    public static function getPrefixPrecedence(TokenType $type): int
    {
        return match ($type) {
            TokenType::Exclamation,
            TokenType::Tilde,
            TokenType::PlusPlus,
            TokenType::MinusMinus,
            TokenType::Minus,
            TokenType::Plus,
            TokenType::Star,
            TokenType::Ampersand => self::PREFIX,

            default => self::NONE,
        };
    }

    public static function isRightAssociative(TokenType $type): bool
    {
        return match ($type) {
            TokenType::Assign,
            TokenType::PlusAssign,
            TokenType::MinusAssign,
            TokenType::StarAssign,
            TokenType::SlashAssign,
            TokenType::PercentAssign,
            TokenType::AmpersandAssign,
            TokenType::PipeAssign,
            TokenType::CaretAssign,
            TokenType::ShiftLeftAssign,
            TokenType::ShiftRightAssign => true,
            default                     => false,
        };
    }
}
