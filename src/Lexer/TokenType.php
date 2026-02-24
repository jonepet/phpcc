<?php

declare(strict_types=1);

namespace Cppc\Lexer;

enum TokenType: string
{
    // Literals
    case IntLiteral = 'INT_LITERAL';
    case FloatLiteral = 'FLOAT_LITERAL';
    case CharLiteral = 'CHAR_LITERAL';
    case StringLiteral = 'STRING_LITERAL';
    case Identifier = 'IDENTIFIER';

    // Keywords - Types
    case Int = 'int';
    case Char = 'char';
    case Bool = 'bool';
    case Void = 'void';
    case Float = 'float';
    case Double = 'double';
    case Long = 'long';
    case Short = 'short';
    case Unsigned = 'unsigned';
    case Signed = 'signed';
    case Const = 'const';
    case Static = 'static';
    case Auto = 'auto';

    // Keywords - Control Flow
    case If = 'if';
    case Else = 'else';
    case While = 'while';
    case For = 'for';
    case Do = 'do';
    case Switch = 'switch';
    case Case = 'case';
    case Default = 'default';
    case Break = 'break';
    case Continue = 'continue';
    case Return = 'return';
    case Goto = 'goto';

    // Keywords - OOP
    case Class_ = 'class';
    case Struct = 'struct';
    case Public = 'public';
    case Private = 'private';
    case Protected = 'protected';
    case Virtual = 'virtual';
    case Override = 'override';
    case New = 'new';
    case Delete = 'delete';
    case This = 'this';
    case Operator = 'operator';

    // Keywords - Advanced
    case Namespace = 'namespace';
    case Using = 'using';
    case Template = 'template';
    case Typename = 'typename';
    case Enum = 'enum';
    case Union = 'union';
    case Typedef = 'typedef';
    case Sizeof = 'sizeof';
    case Nullptr = 'nullptr';
    case Null_ = 'NULL';
    case True_ = 'true';
    case False_ = 'false';
    case StaticCast = 'static_cast';
    case DynamicCast = 'dynamic_cast';
    case ReinterpretCast = 'reinterpret_cast';
    case ConstCast = 'const_cast';
    case Extern = 'extern';
    case Volatile = 'volatile';
    case Register = 'register';
    case Restrict = 'restrict';
    case Inline = 'inline';
    case Friend = 'friend';
    case Explicit = 'explicit';
    case Mutable = 'mutable';
    case Constexpr = 'constexpr';
    case StaticAssert = 'static_assert';
    case Alignof = 'alignof';
    case Alignas = 'alignas';
    case Noexcept = 'noexcept';
    case Decltype = 'decltype';
    case ThreadLocal = 'thread_local';
    case Pure = 'pure';

    // Keywords - Exception handling
    case Try = 'try';
    case Catch = 'catch';
    case Throw = 'throw';

    // Operators
    case Plus = '+';
    case Minus = '-';
    case Star = '*';
    case Slash = '/';
    case Percent = '%';
    case Ampersand = '&';
    case Pipe = '|';
    case Caret = '^';
    case Tilde = '~';
    case Exclamation = '!';
    case Assign = '=';
    case Less = '<';
    case Greater = '>';
    case Dot = '.';
    case Comma = ',';
    case Semicolon = ';';
    case Colon = ':';
    case Question = '?';

    // Multi-char operators
    case PlusPlus = '++';
    case MinusMinus = '--';
    case PlusAssign = '+=';
    case MinusAssign = '-=';
    case StarAssign = '*=';
    case SlashAssign = '/=';
    case PercentAssign = '%=';
    case AmpersandAssign = '&=';
    case PipeAssign = '|=';
    case CaretAssign = '^=';
    case ShiftLeftAssign = '<<=';
    case ShiftRightAssign = '>>=';
    case Equal = '==';
    case NotEqual = '!=';
    case LessEqual = '<=';
    case GreaterEqual = '>=';
    case LogicalAnd = '&&';
    case LogicalOr = '||';
    case ShiftLeft = '<<';
    case ShiftRight = '>>';
    case Arrow = '->';
    case ArrowStar = '->*';
    case DotStar = '.*';
    case DoubleColon = '::';
    case Ellipsis = '...';

    // Delimiters
    case LeftParen = '(';
    case RightParen = ')';
    case LeftBrace = '{';
    case RightBrace = '}';
    case LeftBracket = '[';
    case RightBracket = ']';

    // Special
    case EOF = 'EOF';

    public function isAssignment(): bool
    {
        return match ($this) {
            self::Assign, self::PlusAssign, self::MinusAssign,
            self::StarAssign, self::SlashAssign, self::PercentAssign,
            self::AmpersandAssign, self::PipeAssign, self::CaretAssign,
            self::ShiftLeftAssign, self::ShiftRightAssign => true,
            default => false,
        };
    }

    public function isTypeKeyword(): bool
    {
        return match ($this) {
            self::Int, self::Char, self::Bool, self::Void,
            self::Float, self::Double, self::Long, self::Short,
            self::Unsigned, self::Signed, self::Auto => true,
            default => false,
        };
    }

    public function isTypeQualifier(): bool
    {
        return match ($this) {
            self::Const, self::Static, self::Unsigned, self::Signed,
            self::Long, self::Short, self::Extern, self::Inline,
            self::Virtual, self::Volatile, self::Register, self::Restrict,
            self::Mutable, self::Constexpr, self::ThreadLocal => true,
            default => false,
        };
    }

    /**
     * Whether this token type is a language keyword (as opposed to an
     * operator, literal, punctuation, or identifier).  Used to allow
     * keywords as struct/union member names in C (e.g. X11's `->class`).
     */
    public function isKeyword(): bool
    {
        return match ($this) {
            // Types
            self::Int, self::Char, self::Bool, self::Void,
            self::Float, self::Double, self::Long, self::Short,
            self::Unsigned, self::Signed, self::Auto,
            // Qualifiers
            self::Const, self::Static, self::Extern, self::Inline,
            self::Virtual, self::Volatile, self::Register, self::Restrict,
            self::Mutable, self::Constexpr, self::ThreadLocal,
            // Control flow
            self::If, self::Else, self::While, self::For, self::Do,
            self::Switch, self::Case, self::Default, self::Break,
            self::Continue, self::Return, self::Goto,
            // OOP
            self::Class_, self::Struct, self::Public, self::Private,
            self::Protected, self::Virtual, self::Override, self::New,
            self::Delete, self::This, self::Operator,
            // Advanced
            self::Namespace, self::Using, self::Template, self::Typename,
            self::Enum, self::Union, self::Typedef, self::Sizeof,
            self::Friend, self::Explicit, self::Pure,
            // Casts
            self::StaticCast, self::DynamicCast, self::ReinterpretCast,
            self::ConstCast,
            // Exceptions
            self::Try, self::Catch, self::Throw,
            // Other
            self::Alignof, self::Alignas, self::Noexcept, self::Decltype,
            self::StaticAssert => true,
            default => false,
        };
    }
}
