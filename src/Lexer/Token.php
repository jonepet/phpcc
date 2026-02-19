<?php

declare(strict_types=1);

namespace Cppc\Lexer;

class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $value,
        public readonly int $line,
        public readonly int $column,
        public readonly string $file = '',
    ) {}

    public function __toString(): string
    {
        $val = match ($this->type) {
            TokenType::IntLiteral, TokenType::FloatLiteral,
            TokenType::CharLiteral, TokenType::StringLiteral,
            TokenType::Identifier => " ({$this->value})",
            default => '',
        };
        return "{$this->type->value}{$val} at {$this->file}:{$this->line}:{$this->column}";
    }

    public static array $keywords = [
        // Types
        'int' => TokenType::Int,
        'char' => TokenType::Char,
        'bool' => TokenType::Bool,
        'void' => TokenType::Void,
        'float' => TokenType::Float,
        'double' => TokenType::Double,
        'long' => TokenType::Long,
        'short' => TokenType::Short,
        'unsigned' => TokenType::Unsigned,
        'signed' => TokenType::Signed,

        // Qualifiers & storage
        'const' => TokenType::Const,
        'static' => TokenType::Static,
        'auto' => TokenType::Auto,
        'extern' => TokenType::Extern,
        'volatile' => TokenType::Volatile,
        'register' => TokenType::Register,
        'restrict' => TokenType::Restrict,
        'inline' => TokenType::Inline,
        'mutable' => TokenType::Mutable,
        'constexpr' => TokenType::Constexpr,
        'thread_local' => TokenType::ThreadLocal,

        // Control flow
        'if' => TokenType::If,
        'else' => TokenType::Else,
        'while' => TokenType::While,
        'for' => TokenType::For,
        'do' => TokenType::Do,
        'switch' => TokenType::Switch,
        'case' => TokenType::Case,
        'default' => TokenType::Default,
        'break' => TokenType::Break,
        'continue' => TokenType::Continue,
        'return' => TokenType::Return,
        'goto' => TokenType::Goto,

        // OOP
        'class' => TokenType::Class_,
        'struct' => TokenType::Struct,
        'union' => TokenType::Union,
        'public' => TokenType::Public,
        'private' => TokenType::Private,
        'protected' => TokenType::Protected,
        'virtual' => TokenType::Virtual,
        'override' => TokenType::Override,
        'new' => TokenType::New,
        'delete' => TokenType::Delete,
        'this' => TokenType::This,
        'operator' => TokenType::Operator,
        'friend' => TokenType::Friend,
        'explicit' => TokenType::Explicit,

        // Advanced
        'namespace' => TokenType::Namespace,
        'using' => TokenType::Using,
        'template' => TokenType::Template,
        'typename' => TokenType::Typename,
        'enum' => TokenType::Enum,
        'typedef' => TokenType::Typedef,
        'sizeof' => TokenType::Sizeof,
        'nullptr' => TokenType::Nullptr,
        'NULL' => TokenType::Null_,
        'true' => TokenType::True_,
        'false' => TokenType::False_,
        'static_cast' => TokenType::StaticCast,
        'dynamic_cast' => TokenType::DynamicCast,
        'reinterpret_cast' => TokenType::ReinterpretCast,
        'const_cast' => TokenType::ConstCast,
        'static_assert' => TokenType::StaticAssert,
        'alignof' => TokenType::Alignof,
        'alignas' => TokenType::Alignas,
        'noexcept' => TokenType::Noexcept,
        'decltype' => TokenType::Decltype,

        // Exception handling
        'try' => TokenType::Try,
        'catch' => TokenType::Catch,
        'throw' => TokenType::Throw,
    ];
}
