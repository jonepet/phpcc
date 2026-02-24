<?php

declare(strict_types=1);

namespace Cppc\Parser;

use Cppc\AST\AccessSpecifier;
use Cppc\AST\ArrayAccessExpr;
use Cppc\AST\AssignExpr;
use Cppc\AST\BinaryExpr;
use Cppc\AST\BlockStatement;
use Cppc\AST\BoolLiteral;
use Cppc\AST\BreakStatement;
use Cppc\AST\CallExpr;
use Cppc\AST\CaseClause;
use Cppc\AST\CastExpr;
use Cppc\AST\CommaExpr;
use Cppc\AST\CharLiteralNode;
use Cppc\AST\ClassDeclaration;
use Cppc\AST\ContinueStatement;
use Cppc\AST\DeleteExpr;
use Cppc\AST\DoWhileStatement;
use Cppc\AST\EnumDeclaration;
use Cppc\AST\EnumEntry;
use Cppc\AST\ExpressionStatement;
use Cppc\AST\FloatLiteral;
use Cppc\AST\ForStatement;
use Cppc\AST\FunctionDeclaration;
use Cppc\AST\GotoStatement;
use Cppc\AST\DesignatedInit;
use Cppc\AST\IdentifierExpr;
use Cppc\AST\IfStatement;
use Cppc\AST\InitializerList;
use Cppc\AST\IntLiteral;
use Cppc\AST\LabelStatement;
use Cppc\AST\MemberAccessExpr;
use Cppc\AST\MemberInitializer;
use Cppc\AST\MemberInitializerList;
use Cppc\AST\NamespaceDeclaration;
use Cppc\AST\NewExpr;
use Cppc\AST\Node;
use Cppc\AST\NullptrLiteral;
use Cppc\AST\OperatorDeclaration;
use Cppc\AST\Parameter;
use Cppc\AST\ReturnStatement;
use Cppc\AST\ScopeResolutionExpr;
use Cppc\AST\SizeofExpr;
use Cppc\AST\StringLiteralNode;
use Cppc\AST\SwitchStatement;
use Cppc\AST\TemplateDeclaration;
use Cppc\AST\TemplateParameter;
use Cppc\AST\TernaryExpr;
use Cppc\AST\ThisExpr;
use Cppc\AST\TranslationUnit;
use Cppc\AST\TypedefDeclaration;
use Cppc\AST\TypeNode;
use Cppc\AST\UnaryExpr;
use Cppc\AST\UsingDeclaration;
use Cppc\AST\VarDeclaration;
use Cppc\AST\WhileStatement;
use Cppc\CompileError;
use Cppc\Lexer\Token;
use Cppc\Lexer\TokenType;

/**
 * Recursive-descent parser with Pratt expression parsing.
 *
 * Consumes the flat token array produced by the Lexer and emits an AST rooted
 * at TranslationUnit.  The overall strategy is:
 *
 *   - Declarations are handled with recursive descent (parseDeclaration,
 *     parseClassDeclaration, …).
 *   - Expressions are handled with Pratt top-down operator-precedence
 *     (parseExpression → parsePrefixExpression / parseInfixExpression).
 *   - Statements are handled with recursive descent (parseStatement, …).
 *
 * Error recovery: on a parse error we throw CompileError.  Where recovery is
 * possible (e.g. inside a block) we attempt to skip to the next `;` or `}`.
 */
final class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int $pos = 0;

    /** Tracks whether we are inside a template argument list `<…>`. */
    private int $templateDepth = 0;

    /** Current extern linkage (e.g. "C") when inside `extern "C" { … }`. */
    private ?string $currentLinkage = null;

    /** Set by parseParameterList() when a trailing `...` was consumed. */
    private bool $lastParamListWasVariadic = false;

    /**
     * Set of user-defined type names encountered so far (class/struct/enum/typedef names).
     * Used to disambiguate declarations from expressions.
     *
     * @var array<string, true>
     */
    private array $typeNames = [];

    /** @param Token[] $tokens */
    public function __construct(array $tokens)
    {
        // Filter out any EOF tokens that may have been duplicated, keeping exactly one at the end.
        $filtered = [];
        foreach ($tokens as $tok) {
            if ($tok->type !== TokenType::EOF) {
                $filtered[] = $tok;
            }
        }
        // Append a single EOF sentinel.
        $eof = end($tokens);
        if ($eof === false) {
            // Empty token list – synthesise a safe EOF.
            $filtered[] = new Token(TokenType::EOF, '', 0, 0);
        } else {
            $filtered[] = new Token(TokenType::EOF, '', $eof->line, $eof->column, $eof->file);
        }
        $this->tokens = $filtered;
    }

    public function parse(): TranslationUnit
    {
        return $this->parseTranslationUnit();
    }

    private function parseTranslationUnit(): TranslationUnit
    {
        $decls = [];
        while (!$this->check(TokenType::EOF)) {
            $this->parseExternCOrDeclaration($decls);
        }
        $unit = new TranslationUnit($decls);
        $unit->setLocation(0, 0);
        return $unit;
    }

    /**
     * Handle `extern "C" { … }` or `extern "C" <decl>` at file scope,
     * falling back to a normal parseDeclaration() otherwise.
     *
     * @param Node[] &$decls  Declarations are appended here (may be >1 for block form).
     */
    private function parseExternCOrDeclaration(array &$decls): void
    {
        if (
            $this->check(TokenType::Extern)
            && $this->peek(1)?->type === TokenType::StringLiteral
            && $this->peek(1)?->value === 'C'
        ) {
            $this->advance(); // consume 'extern'
            $this->advance(); // consume '"C"'

            $prevLinkage = $this->currentLinkage;
            $this->currentLinkage = 'C';

            if ($this->check(TokenType::LeftBrace)) {
                // Block form: extern "C" { … }
                $this->advance(); // consume '{'
                while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::EOF)) {
                    $decls[] = $this->parseDeclaration();
                }
                $this->expect(TokenType::RightBrace);
            } else {
                // Single-declaration form: extern "C" int printf(…);
                $decls[] = $this->parseDeclaration();
            }

            $this->currentLinkage = $prevLinkage;
            return;
        }

        $decls[] = $this->parseDeclaration();
    }

    /**
     * Entry point for any declaration at namespace or class scope.
     * Disambiguates between: function, variable, class, struct, enum,
     * namespace, template, typedef, using.
     */
    private function parseDeclaration(): Node
    {
        $this->skipGccExtensions();
        $tok = $this->current();

        if ($tok->type === TokenType::Template) {
            return $this->parseTemplateDeclaration();
        }

        if ($tok->type === TokenType::Namespace) {
            return $this->parseNamespaceDeclaration();
        }

        if ($tok->type === TokenType::Typedef) {
            return $this->parseTypedefDeclaration();
        }

        if ($tok->type === TokenType::Using) {
            return $this->parseUsingDeclaration();
        }

        if ($tok->type === TokenType::Class_ || $tok->type === TokenType::Struct || $tok->type === TokenType::Union) {
            // Distinguish struct/class/union DEFINITION from variable declaration using a struct type.
            // `struct Foo { ... }` or `struct Foo;` → definition/forward decl
            // `struct Foo var;` or `struct Foo* ptr;` → variable declaration
            $next = $this->peek(1);
            if ($next->type === TokenType::LeftBrace) {
                // `struct { ... }` — anonymous definition
                return $this->parseClassOrStructDeclaration();
            }
            // `struct __attribute__((...)) Foo { ... }` — attribute before tag name
            if ($next->type === TokenType::Identifier && $this->isAttributeIdentifier($next->value)) {
                return $this->parseClassOrStructDeclaration();
            }
            if ($next->type === TokenType::Identifier) {
                $afterName = $this->peek(2);
                if ($afterName->type === TokenType::LeftBrace
                    || $afterName->type === TokenType::Semicolon
                    || $afterName->type === TokenType::Colon
                ) {
                    // `struct Foo {`, `struct Foo;`, `struct Foo :` → definition
                    return $this->parseClassOrStructDeclaration();
                }
            }
            // Otherwise: it's a variable/function declaration using the struct type.
            // Fall through to parseFunctionOrVarDeclaration below.
        }

        if ($tok->type === TokenType::Enum) {
            return $this->parseEnumDeclaration();
        }

        if ($tok->type === TokenType::Friend) {
            $this->advance(); // consume 'friend'
            return $this->parseDeclaration();
        }

        // Everything else starts with a type specifier sequence followed by a
        // declarator, which is either a function or a variable.
        $type = $this->parseType();
        return $this->parseFunctionOrVarDeclaration($type);
    }

    /**
     * After we have parsed a leading type, decide whether this is a function
     * or a variable declaration.  Handles class-qualified names such as
     * `Foo::bar(…)`.
     */
    private function parseFunctionOrVarDeclaration(TypeNode $type): Node
    {
        $this->skipGccExtensions();

        // operator overload: `operator+(…)`
        if ($this->check(TokenType::Operator)) {
            return $this->parseOperatorDeclaration($type);
        }

        // Collect optional scope qualification: Foo::
        $className = null;
        $name      = '';

        if ($this->check(TokenType::Identifier) && !$this->isAttributeIdentifier($this->current()->value)) {
            $nameTok = $this->advance();
            $name    = $nameTok->value;

            // Foo::bar  — class-qualified definition
            if ($this->check(TokenType::DoubleColon)) {
                $this->advance();

                // Could be destructor: Foo::~Foo()
                if ($this->check(TokenType::Tilde)) {
                    $this->advance();
                    $dtorNameTok = $this->expect(TokenType::Identifier);
                    $className   = $name;
                    $name        = '~' . $dtorNameTok->value;
                    return $this->parseFunctionDeclaration($type, $name, $className);
                }

                if ($this->check(TokenType::Operator)) {
                    $className = $name;
                    return $this->parseOperatorDeclaration($type, $className);
                }

                $memberTok = $this->expect(TokenType::Identifier);
                $className = $name;
                $name      = $memberTok->value;
            }
        }

        // Parenthesized function name: `type ( name ) ( params )` — macro protection pattern.
        // Used by libpng and others to prevent macro expansion of function names.
        if ($name === '' && $this->check(TokenType::LeftParen) && $this->peek(1)?->type === TokenType::Identifier) {
            $peek2 = $this->peek(2);
            if ($peek2 !== null && $peek2->type === TokenType::RightParen) {
                $this->advance(); // consume '('
                $name = $this->advance()->value; // consume name
                $this->advance(); // consume ')'
                // Fall through to normal function/var parsing below with the extracted name
            }
        }

        // Function returning function pointer: `int (*XSynchronize(Display*, int))(Display*);`
        // Pattern: `type (*name(params))(params);`
        if ($name === '' && $this->check(TokenType::LeftParen) && $this->peek(1)?->type === TokenType::Star) {
            $this->advance(); // consume '('
            $this->advance(); // consume '*'
            if ($this->check(TokenType::Identifier)) {
                $name = $this->advance()->value;
                // Check if this is a function returning function pointer
                if ($this->check(TokenType::LeftParen)) {
                    // It's `(*name(params))(params)` — parse as regular function declaration
                    // Skip the entire declaration until ';'
                    $depth = 1; // inside first '('
                    while (!$this->check(TokenType::EOF)) {
                        if ($this->check(TokenType::Semicolon) && $depth === 0) {
                            $this->advance();
                            break;
                        }
                        if ($this->check(TokenType::LeftParen)) $depth++;
                        if ($this->check(TokenType::RightParen)) $depth--;
                        $this->advance();
                    }
                    // Return a no-op declaration (function prototype we can't handle)
                    $tok = $this->current();
                    return (new ExpressionStatement(
                        (new IntLiteral(0))->setLocation($tok->line, $tok->column, $tok->file)
                    ))->setLocation($tok->line, $tok->column, $tok->file);
                }
            }
        }

        if ($name === '') {
            $this->error("Expected identifier in declaration");
        }

        // Disambiguate: if the next token is `(`, this is a function.
        if ($this->check(TokenType::LeftParen)) {
            return $this->parseFunctionDeclaration($type, $name, $className);
        }

        return $this->parseVarDeclaration($type, $name);
    }

    /**
     * Parse a function declaration / definition.
     * Precondition: type and name are already parsed; current token is `(`.
     *
     * Supports comma-separated forward declarations sharing the same base type:
     *   `extern int func1(int a), func2(char *b), func3(void);`
     * Each declarator becomes its own FunctionDeclaration node.  When more than
     * one declarator is present the nodes are wrapped in a BlockStatement.
     *
     * @param string|null $className  For out-of-class definitions like `Foo::bar`.
     */
    private function parseFunctionDeclaration(
        TypeNode $type,
        string   $name,
        ?string  $className = null,
    ): FunctionDeclaration|BlockStatement {
        $firstDecl = $this->parseSingleFunctionDeclarator($type, $name, $className);

        // parseSingleFunctionDeclarator leaves `,` unconsumed when it is a
        // forward declaration followed by more declarators.  If the current
        // token is not `,`, just return the single node.
        if (!$this->check(TokenType::Comma)) {
            return $firstDecl;
        }

        // Comma-separated forward declarations: consume additional declarators.
        $decls = [$firstDecl];
        $tok   = $this->current();

        while ($this->check(TokenType::Comma)) {
            $this->advance(); // consume ','

            // Each additional declarator may have its own pointer depth.
            $extraType = clone $type;
            $extraType->pointerDepth = 0;
            while ($this->check(TokenType::Star)) {
                $this->advance();
                $extraType->pointerDepth++;
                while (
                    $this->check(TokenType::Const)
                    || $this->check(TokenType::Restrict)
                    || $this->check(TokenType::Volatile)
                ) {
                    $this->advance();
                }
            }

            $extraName = $this->expect(TokenType::Identifier)->value;

            if (!$this->check(TokenType::LeftParen)) {
                $this->error("Expected '(' after function name in comma-separated declaration");
            }

            $decls[] = $this->parseSingleFunctionDeclarator($extraType, $extraName, $className);
        }

        // parseSingleFunctionDeclarator already consumed ';' when it was the
        // terminator of the last declarator in the list.  Only consume it here
        // if it is still present (which can happen if the last declarator left
        // it unconsumed for some reason).
        if ($this->check(TokenType::Semicolon)) {
            $this->advance();
        }

        return (new BlockStatement($decls))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    /**
     * Parse a single function declarator starting at `(`.
     *
     * For forward declarations (no body), when the token after all trailing
     * qualifiers/attributes is `,`, the `,` is left unconsumed so the caller
     * (parseFunctionDeclaration) can loop over additional declarators.
     * When the terminator is `;`, it is consumed normally.
     *
     * @param string|null $className  For out-of-class definitions like `Foo::bar`.
     */
    private function parseSingleFunctionDeclarator(
        TypeNode $type,
        string   $name,
        ?string  $className = null,
    ): FunctionDeclaration {
        $tok         = $this->current();
        $isVirtual   = $type->isVirtual;
        $isStatic    = $type->isStatic;
        $isInline    = $type->isInline;
        $isCtor      = false;
        $isDtor      = false;

        // Constructors / destructors: return type is synthetic void.
        if ($className !== null) {
            $isCtor = ($name === $className);
            $isDtor = (str_starts_with($name, '~'));
        }

        $this->expect(TokenType::LeftParen);
        $params = $this->parseParameterList();
        $isVariadic = $this->lastParamListWasVariadic;
        $this->expect(TokenType::RightParen);

        // Skip __asm__("symbol") renaming annotation and any __attribute__((...)) here.
        $this->skipGccExtensions();

        $linkage = $this->currentLinkage;

        // Trailing const qualifier: `void foo() const`
        $isMethodConst = false;
        if ($this->check(TokenType::Const)) {
            $this->advance();
            $isMethodConst = true;
        }

        $isOverride = false;
        if ($this->check(TokenType::Override)) {
            $this->advance();
            $isOverride = true;
        }

        $this->skipAttribute();

        // Pure virtual: `= 0`
        $isPureVirtual = false;
        if ($this->check(TokenType::Assign) && $isVirtual) {
            $this->advance();
            $zeroTok = $this->expect(TokenType::IntLiteral);
            if ($zeroTok->value !== '0') {
                $this->error("Expected '0' after '=' in pure virtual declaration", $zeroTok);
            }
            $isPureVirtual = true;
        }

        $this->skipAttribute();

        // Forward declaration (no body).
        // When next token is `,` this is part of a comma-separated list; leave
        // the `,` unconsumed so parseFunctionDeclaration can loop over it.
        if ($this->check(TokenType::Semicolon) || $this->check(TokenType::Comma)) {
            if ($this->check(TokenType::Semicolon)) {
                $this->advance(); // consume ';'
            }
            // ',' is intentionally left for the caller.
            return (new FunctionDeclaration(
                returnType:        $type,
                name:              $name,
                parameters:        $params,
                body:              null,
                isVirtual:         $isVirtual,
                isPureVirtual:     $isPureVirtual,
                isStatic:          $isStatic,
                isInline:          $isInline,
                isConst:           $isMethodConst,
                className:         $className,
                isOverride:        $isOverride,
                isConstructor:     $isCtor,
                isDestructor:      $isDtor,
                memberInitializers: null,
                linkage:           $linkage,
                isVariadic:        $isVariadic,
            ))->setLocation($tok->line, $tok->column, $tok->file);
        }

        // Constructor member-initialiser list: `Foo() : m_x(0), m_y(1) { … }`
        $memberInitList = null;
        if ($this->check(TokenType::Colon) && $isCtor) {
            $memberInitList = $this->parseMemberInitializerList();
        }

        $this->expect(TokenType::LeftBrace);
        $body = $this->parseBlock();

        return (new FunctionDeclaration(
            returnType:         $type,
            name:               $name,
            parameters:         $params,
            body:               $body,
            isVirtual:          $isVirtual,
            isPureVirtual:      $isPureVirtual,
            isStatic:           $isStatic,
            isInline:           $isInline,
            isConst:            $isMethodConst,
            className:          $className,
            isOverride:         $isOverride,
            isConstructor:      $isCtor,
            isDestructor:       $isDtor,
            memberInitializers: $memberInitList,
            linkage:            $linkage,
            isVariadic:         $isVariadic,
        ))->setLocation($tok->line, $tok->column, $tok->file);
    }

    /**
     * Parse an operator overload declaration: `operator+(…)`.
     *
     * @param string|null $className  Class scope when parsing out-of-class.
     */
    private function parseOperatorDeclaration(
        TypeNode $returnType,
        ?string  $className = null,
    ): OperatorDeclaration {
        $tok = $this->current();
        $this->expect(TokenType::Operator);

        // Collect the operator symbol — may be one or two tokens.
        $symbol = $this->parseOperatorSymbol();

        $this->expect(TokenType::LeftParen);
        $params = $this->parseParameterList();
        $this->expect(TokenType::RightParen);

        $isConst = false;
        if ($this->check(TokenType::Const)) {
            $this->advance();
            $isConst = true;
        }

        $isVirtual = $returnType->isVirtual;

        if ($this->check(TokenType::Semicolon)) {
            $this->advance();
            return (new OperatorDeclaration(
                returnType:     $returnType,
                operatorSymbol: $symbol,
                parameters:     $params,
                body:           null,
                isVirtual:      $isVirtual,
                isConst:        $isConst,
                className:      $className,
            ))->setLocation($tok->line, $tok->column, $tok->file);
        }

        $this->expect(TokenType::LeftBrace);
        $body = $this->parseBlock();

        return (new OperatorDeclaration(
            returnType:     $returnType,
            operatorSymbol: $symbol,
            parameters:     $params,
            body:           $body,
            isVirtual:      $isVirtual,
            isConst:        $isConst,
            className:      $className,
        ))->setLocation($tok->line, $tok->column, $tok->file);
    }

    /**
     * Consume the operator symbol tokens after the `operator` keyword and
     * return them as a single string (e.g. `+`, `[]`, `()`, `<<`, `=`, …).
     */
    private function parseOperatorSymbol(): string
    {
        $tok = $this->current();
        // Special case: `()` and `[]` are two-token symbols.
        if ($tok->type === TokenType::LeftParen) {
            $this->advance();
            $this->expect(TokenType::RightParen);
            return '()';
        }
        if ($tok->type === TokenType::LeftBracket) {
            $this->advance();
            $this->expect(TokenType::RightBracket);
            return '[]';
        }
        // Single-token operators: most arithmetic / comparison operators.
        $this->advance();
        return $tok->value;
    }

    private function parseVarDeclaration(TypeNode $type, string $name): Node
    {
        $tok     = $this->current();
        $isArray = false;
        $arrSize = null;
        $init    = null;
        $arrInit = null;

        // Array declarator: `name[size]` or multi-dimensional `name[a][b]`
        if ($this->check(TokenType::LeftBracket)) {
            $isArray = true;
            while ($this->check(TokenType::LeftBracket)) {
                $this->advance();
                if (!$this->check(TokenType::RightBracket)) {
                    $dim = $this->parseExpression();
                    if ($arrSize === null) {
                        $arrSize = $dim;
                    } elseif ($arrSize instanceof IntLiteral && $dim instanceof IntLiteral) {
                        // Flatten multi-dimensional: int a[4][4] → int a[16]
                        $arrSize = (new IntLiteral($arrSize->value * $dim->value))
                            ->setLocation($tok->line, $tok->column, $tok->file);
                    }
                }
                $this->expect(TokenType::RightBracket);
            }
        }

        // Bit field: `unsigned int adjoin : 1;`
        $bitWidth = null;
        if ($this->check(TokenType::Colon) && !$isArray) {
            $this->advance();
            $bwExpr = $this->parseExpression(Precedence::COMMA);
            if ($bwExpr instanceof IntLiteral) {
                $bitWidth = $bwExpr->value;
            }
        }

        // Initialiser
        if ($this->check(TokenType::Assign)) {
            $this->advance();

            if ($this->check(TokenType::LeftBrace)) {
                // Array / aggregate initialiser list: `= { 1, 2, 3 }`
                $arrInit = $this->parseInitializerList();
            } else {
                $init = $this->parseExpression(Precedence::COMMA);
            }
        } elseif ($this->check(TokenType::LeftParen)) {
            // Constructor-call init: `MyClass obj(arg1, arg2)` — represent as CallExpr.
            $this->advance();
            $args = $this->parseArgumentList();
            $this->expect(TokenType::RightParen);
            $ident = (new IdentifierExpr($name))->setLocation($tok->line, $tok->column, $tok->file);
            $init  = (new CallExpr($ident, $args))->setLocation($tok->line, $tok->column, $tok->file);
        } elseif ($this->check(TokenType::LeftBrace)) {
            // Uniform initialisation: `MyClass obj{ arg }`
            $arrInit = $this->parseInitializerList();
        }

        $firstDecl = (new VarDeclaration(
            type:        $type,
            name:        $name,
            initializer: $init,
            isArray:     $isArray,
            arraySize:   $arrSize,
            arrayInit:   $arrInit,
            bitWidth:    $bitWidth,
        ))->setLocation($tok->line, $tok->column, $tok->file);

        // Multiple declarators: `int a = 1, b, *c;`
        if (!$this->check(TokenType::Comma)) {
            $this->expect(TokenType::Semicolon);
            return $firstDecl;
        }

        $decls = [$firstDecl];
        while ($this->check(TokenType::Comma)) {
            $this->advance(); // consume ','

            // Each additional declarator can have its own pointer depth.
            $extraType = clone $type;
            $extraType->pointerDepth = 0;
            while ($this->check(TokenType::Star)) {
                $this->advance();
                $extraType->pointerDepth++;
                // Skip qualifiers after '*': const, volatile, restrict
                while ($this->check(TokenType::Const) || $this->check(TokenType::Volatile) || $this->check(TokenType::Restrict)) {
                    if ($this->current()->type === TokenType::Const) {
                        $extraType->isConst = true;
                    }
                    $this->advance();
                }
            }

            $extraName = $this->expect(TokenType::Identifier)->value;

            $extraArr = false;
            $extraArrSz = null;
            $extraInit = null;
            $extraArrInit = null;

            // Multi-dimensional arrays: `name[a][b]` → flatten
            while ($this->check(TokenType::LeftBracket)) {
                $this->advance();
                $extraArr = true;
                if (!$this->check(TokenType::RightBracket)) {
                    $dim = $this->parseExpression();
                    if ($extraArrSz === null) {
                        $extraArrSz = $dim;
                    } elseif ($extraArrSz instanceof IntLiteral && $dim instanceof IntLiteral) {
                        $extraArrSz = (new IntLiteral($extraArrSz->value * $dim->value))
                            ->setLocation($tok->line, $tok->column, $tok->file);
                    }
                }
                $this->expect(TokenType::RightBracket);
            }

            if ($this->check(TokenType::Assign)) {
                $this->advance();
                if ($this->check(TokenType::LeftBrace)) {
                    $extraArrInit = $this->parseInitializerList();
                } else {
                    $extraInit = $this->parseExpression(Precedence::COMMA);
                }
            }

            $decls[] = (new VarDeclaration(
                type:        $extraType,
                name:        $extraName,
                initializer: $extraInit,
                isArray:     $extraArr,
                arraySize:   $extraArrSz,
                arrayInit:   $extraArrInit,
            ))->setLocation($tok->line, $tok->column, $tok->file);
        }

        $this->expect(TokenType::Semicolon);

        return (new BlockStatement($decls))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    /** @return Parameter[] */
    private function parseParameterList(): array
    {
        $params = [];
        $this->lastParamListWasVariadic = false;

        if ($this->check(TokenType::RightParen)) {
            return $params;
        }

        // void parameter list: `foo(void)`
        if ($this->check(TokenType::Void) && $this->peek(1)->type === TokenType::RightParen) {
            $this->advance();
            return $params;
        }

        do {
            // Variadic: `...`
            if ($this->check(TokenType::Ellipsis)) {
                $this->advance();
                $this->lastParamListWasVariadic = true;
                break;
            }

            $paramTok  = $this->current();
            $paramType = $this->parseType();
            $paramName = '';

            // Function pointer parameter: `int (*fn)(int, int)`
            // or pointer-to-array parameter: `double (*weights)[4]`
            if ($this->check(TokenType::LeftParen) && $this->peek(1)->type === TokenType::Star) {
                $returnType = $paramType;
                $this->advance(); // consume '('
                $this->advance(); // consume '*'
                if ($this->check(TokenType::Identifier)) {
                    $paramName = $this->advance()->value;
                }
                $this->expect(TokenType::RightParen);

                if ($this->check(TokenType::LeftBracket)) {
                    // Pointer-to-array: `double (*weights)[4]`
                    while ($this->check(TokenType::LeftBracket)) {
                        $this->advance();
                        if (!$this->check(TokenType::RightBracket)) {
                            $this->parseExpression(); // discard size
                        }
                        $this->expect(TokenType::RightBracket);
                    }
                    $paramType->pointerDepth++;
                    $params[] = (new Parameter($paramType, $paramName, null))
                        ->setLocation($paramTok->line, $paramTok->column, $paramTok->file);
                    continue;
                }

                // Parse the function pointer parameter types
                $this->expect(TokenType::LeftParen);
                $fpParamTypes = [];
                if (!$this->check(TokenType::RightParen)) {
                    do {
                        if ($this->check(TokenType::Ellipsis)) {
                            $this->advance();
                            break;
                        }
                        $fpType = $this->parseType();
                        // Nested function pointer param: void *(*work)(char *)
                        if ($this->check(TokenType::LeftParen) && $this->peek(1)?->type === TokenType::Star) {
                            $this->advance(); // consume '('
                            $this->advance(); // consume '*'
                            if ($this->check(TokenType::Identifier)) {
                                $this->advance(); // discard name
                            }
                            $this->expect(TokenType::RightParen);
                            // Parse nested function pointer's parameters
                            if ($this->check(TokenType::LeftParen)) {
                                $this->advance(); // consume '('
                                while (!$this->check(TokenType::RightParen) && !$this->check(TokenType::EOF)) {
                                    if ($this->check(TokenType::Ellipsis)) {
                                        $this->advance();
                                        break;
                                    }
                                    $this->parseType();
                                    if ($this->check(TokenType::Identifier)) {
                                        $this->advance();
                                    }
                                    if ($this->check(TokenType::Comma)) {
                                        $this->advance();
                                    }
                                }
                                $this->expect(TokenType::RightParen);
                            }
                            $fpType->pointerDepth = 1; // function pointers are pointer-sized
                        } elseif ($this->check(TokenType::Identifier)) {
                            $this->advance(); // discard parameter name
                        }
                        // Skip array notation in function pointer params: `In[]`
                        if ($this->check(TokenType::LeftBracket)) {
                            $this->advance();
                            if (!$this->check(TokenType::RightBracket)) {
                                $this->parseExpression();
                            }
                            $this->expect(TokenType::RightBracket);
                            $fpType->pointerDepth++;
                        }
                        $fpParamTypes[] = $fpType;
                    } while ($this->check(TokenType::Comma) && $this->advance() !== null);
                }
                $this->expect(TokenType::RightParen);
                $paramType = TypeNode::functionPointer($returnType, $fpParamTypes);
                $params[] = (new Parameter($paramType, $paramName, null))
                    ->setLocation($paramTok->line, $paramTok->column, $paramTok->file);
                continue;
            }

            if ($this->check(TokenType::Identifier)) {
                $paramName = $this->advance()->value;
            }

            // Skip any GCC extensions after parameter name, e.g. __attribute__((unused))
            $this->skipGccExtensions();

            // Array parameter: `int arr[]` or `int arr[restrict]` or `int arr[static 10]`
            while ($this->check(TokenType::LeftBracket)) {
                $this->advance();
                // Skip qualifiers inside [] brackets: restrict, const, volatile, static
                while ($this->check(TokenType::Restrict) || $this->check(TokenType::Const)
                    || $this->check(TokenType::Volatile) || $this->check(TokenType::Static)) {
                    $this->advance();
                }
                if (!$this->check(TokenType::RightBracket)) {
                    $this->parseExpression(); // discard size
                }
                $this->expect(TokenType::RightBracket);
                $paramType->isArray = true;
            }

            $default = null;
            if ($this->check(TokenType::Assign)) {
                $this->advance();
                $default = $this->parseExpression(Precedence::COMMA);
            }

            $params[] = (new Parameter($paramType, $paramName, $default))
                ->setLocation($paramTok->line, $paramTok->column, $paramTok->file);

        } while ($this->check(TokenType::Comma) && $this->advance() !== null);

        return $params;
    }

    /** Parse a constructor member-initialiser list: `: m_x(0), m_y(val)`. */
    private function parseMemberInitializerList(): MemberInitializerList
    {
        $tok = $this->current();
        $this->expect(TokenType::Colon);
        $inits = [];

        do {
            $initTok  = $this->current();
            $initName = $this->expect(TokenType::Identifier)->value;
            $this->expect(TokenType::LeftParen);
            $args = $this->parseArgumentList();
            $this->expect(TokenType::RightParen);
            $inits[] = (new MemberInitializer($initName, $args))
                ->setLocation($initTok->line, $initTok->column, $initTok->file);
        } while ($this->check(TokenType::Comma) && $this->advance() !== null);

        return (new MemberInitializerList($inits))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    /**
     * Parse a type specifier sequence, optionally followed by pointer / reference
     * declarators.  Examples:
     *   `const int*`
     *   `unsigned long long`
     *   `std::vector<int>&`
     *   `const char* const`
     */
    private function parseType(): TypeNode
    {
        $tok       = $this->current();
        $isConst   = false;
        $isStatic  = false;
        $isExtern  = false;
        $isInline  = false;
        $isVirtual = false;
        $isUnsigned = false;
        $isSigned  = false;
        $isLong    = false;
        $isShort   = false;
        $longCount = 0;

        // Consume leading qualifiers and storage-class specifiers.
        // Type modifiers (unsigned, signed, long, short) and type keywords (int, char, etc.)
        // can appear in any order in C: `unsigned long int` == `long unsigned int` == `int long unsigned`.
        $keepGoing = true;
        while ($keepGoing) {
            $this->skipGccExtensions();
            switch ($this->current()->type) {
                case TokenType::Const:
                    $isConst = true;
                    $this->advance();
                    break;
                case TokenType::Static:
                    $isStatic = true;
                    $this->advance();
                    break;
                case TokenType::Extern:
                    $isExtern = true;
                    $this->advance();
                    break;
                case TokenType::Inline:
                    $isInline = true;
                    $this->advance();
                    break;
                case TokenType::Virtual:
                    $isVirtual = true;
                    $this->advance();
                    break;
                case TokenType::Unsigned:
                    $isUnsigned = true;
                    $this->advance();
                    break;
                case TokenType::Signed:
                    $isSigned = true;
                    $this->advance();
                    break;
                case TokenType::Long:
                    $isLong = true;
                    $longCount++;
                    $this->advance();
                    break;
                case TokenType::Short:
                    $isShort = true;
                    $this->advance();
                    break;
                case TokenType::Volatile:
                case TokenType::Restrict:
                case TokenType::Register:
                case TokenType::Mutable:
                case TokenType::Constexpr:
                case TokenType::ThreadLocal:
                    $this->advance();
                    break;
                default:
                    $keepGoing = false;
            }
        }
        $this->skipGccExtensions();

        $baseName      = 'int';
        $namespacePath = null;
        $templateParam = null;
        $className     = null;

        $cur = $this->current();

        if ($cur->type->isTypeKeyword()) {
            $baseName = $cur->value;
            $this->advance();
            // C99 _Complex / __complex__ modifier after base type (e.g., `double _Complex`)
            if ($this->check(TokenType::Identifier)
                && ($this->current()->value === '_Complex' || $this->current()->value === '__complex__'
                    || $this->current()->value === '_Imaginary')
            ) {
                $this->advance(); // skip — complex math not implemented, treat as base type
            }
        } elseif ($cur->type === TokenType::Identifier
            && ($cur->value === '_Complex' || $cur->value === '__complex__' || $cur->value === '_Imaginary')
        ) {
            // C99 _Complex before base type (e.g., `_Complex double`, `_Complex _Float32`)
            $this->advance();
            if ($this->current()->type->isTypeKeyword()) {
                $baseName = $this->current()->value;
                $this->advance();
            } elseif ($this->check(TokenType::Identifier) && str_starts_with($this->current()->value, '_Float')) {
                $baseName = str_contains($this->current()->value, '32') ? 'float' : 'double';
                $this->advance();
            }
        } elseif ($cur->type === TokenType::Identifier && ($cur->value === '_Float32' || $cur->value === '_Float32x'
            || $cur->value === '_Float64' || $cur->value === '_Float64x'
            || $cur->value === '_Float128' || $cur->value === '_Float128x')
        ) {
            // GCC extended float types — treat as float/double for compatibility.
            $baseName = str_contains($cur->value, '32') ? 'float' : 'double';
            $this->advance();
            // May be preceded or followed by _Complex
            if ($this->check(TokenType::Identifier)
                && ($this->current()->value === '_Complex' || $this->current()->value === '__complex__')
            ) {
                $this->advance();
            }
        } elseif ($cur->type === TokenType::Identifier && ($cur->value === '__int128' || $cur->value === '__int128_t' || $cur->value === '__uint128_t')) {
            // Treat 128-bit integer types as long long (aliased for compatibility).
            $baseName = 'long long';
            $isLong = true;
            $longCount = 2;
            $this->advance();
        } elseif ($cur->type === TokenType::Identifier && ($cur->value === '__typeof__' || $cur->value === 'typeof' || $cur->value === '__typeof')) {
            // GCC __typeof__(expr-or-type) — parse the inner type/expression and return it.
            $this->advance(); // consume __typeof__
            $this->expect(TokenType::LeftParen);
            $innerType = $this->parseTypeofInner();
            $this->expect(TokenType::RightParen);
            // Propagate qualifiers collected above onto the result.
            return (new TypeNode(
                baseName:      $innerType->baseName,
                isConst:       $isConst || $innerType->isConst,
                isUnsigned:    $innerType->isUnsigned,
                isSigned:      $innerType->isSigned,
                isLong:        $innerType->isLong,
                isShort:       $innerType->isShort,
                isStatic:      $isStatic,
                isExtern:      $isExtern,
                isInline:      $isInline,
                isVirtual:     $isVirtual,
                pointerDepth:  $innerType->pointerDepth,
                isReference:   $innerType->isReference,
                templateParam: $innerType->templateParam,
                className:     $innerType->className,
                namespacePath: $innerType->namespacePath,
            ))->setLocation($tok->line, $tok->column, $tok->file);
        } elseif ($cur->type === TokenType::Identifier
            && (!($isUnsigned || $isSigned || $isLong || $isShort) || isset($this->typeNames[$cur->value]))
        ) {
            // User-defined type name, optionally namespace-qualified.
            // Only consume as type if no modifier flags are set, or if identifier is a known typedef.
            $baseName = $cur->value;
            $this->advance();

            // Namespace qualification chain: `std::vector` / `Foo::Bar`
            while ($this->check(TokenType::DoubleColon) && $this->peek(1)->type === TokenType::Identifier) {
                $this->advance();
                $part          = $this->advance()->value;
                $namespacePath = ($namespacePath !== null) ? $namespacePath . '::' . $baseName : $baseName;
                $baseName      = $part;
            }
        } elseif ($cur->type === TokenType::Struct || $cur->type === TokenType::Class_ || $cur->type === TokenType::Union || $cur->type === TokenType::Enum) {
            // Inline struct/class/union/enum type used as a type specifier.
            $typeKeyword = $cur->type;
            $this->advance();
            // Skip __attribute__((...)) between keyword and tag name:
            // e.g. `struct __attribute__((__may_alias__)) sockaddr`
            $this->skipGccExtensions();
            if ($this->check(TokenType::Identifier)) {
                $rawName = $this->advance()->value;
                $this->skipGccExtensions();
            } else {
                $rawName = 'anonymous';
            }
            // If followed by `{`, this is an inline struct/union/enum definition
            // used as a type specifier: `struct { int x; } var;`
            // Skip the body — we can't define types inside parseType().
            if ($this->check(TokenType::LeftBrace)) {
                $depth = 0;
                while (!$this->check(TokenType::EOF)) {
                    if ($this->check(TokenType::LeftBrace)) {
                        $depth++;
                    } elseif ($this->check(TokenType::RightBrace)) {
                        $depth--;
                        if ($depth === 0) {
                            $this->advance();
                            break;
                        }
                    }
                    $this->advance();
                }
            }
            $className = $rawName;
            if ($typeKeyword === TokenType::Struct) {
                $baseName = "struct:{$rawName}";
            } elseif ($typeKeyword === TokenType::Union) {
                $baseName = "union:{$rawName}";
            } elseif ($typeKeyword === TokenType::Enum) {
                $baseName = "enum:{$rawName}";
            } else {
                $baseName = $rawName;
            }
        } elseif ($isUnsigned || $isSigned || $isLong || $isShort) {
            // Qualifiers only, implicit `int`.
            $baseName = 'int';
        } else {
            $this->error("Expected type specifier");
        }

        // Template argument: `vector<int>`
        if ($this->check(TokenType::Less) && !$this->isComparisonContext()) {
            $this->advance();
            $this->templateDepth++;
            $templateParam = $this->parseType();
            $this->templateDepth--;
            $this->expect(TokenType::Greater);
        }

        // Post-base-type qualifiers: `void const *` / `int volatile *`
        while ($this->check(TokenType::Const) || $this->check(TokenType::Volatile) || $this->check(TokenType::Restrict)) {
            if ($this->current()->type === TokenType::Const) {
                $isConst = true;
            }
            $this->advance();
        }

        // Pointer / reference declarators (may repeat for multi-level pointers).
        $pointerDepth = 0;
        $isReference  = false;

        while (true) {
            if ($this->check(TokenType::Star)) {
                $this->advance();
                $pointerDepth++;
                // Pointer-to-const or pointer-to-restrict: `int * const`, `int * restrict`
                while ($this->check(TokenType::Const) || $this->check(TokenType::Restrict) || $this->check(TokenType::Volatile)) {
                    $this->advance();
                }
                $this->skipGccExtensions();
            } elseif ($this->check(TokenType::Ampersand)) {
                $this->advance();
                $isReference = true;
                break; // References cannot be further dereferenced.
            } else {
                break;
            }
        }

        return (new TypeNode(
            baseName:      $baseName,
            isConst:       $isConst,
            isUnsigned:    $isUnsigned,
            isSigned:      $isSigned,
            isLong:        $isLong,
            isShort:       $isShort,
            isStatic:      $isStatic,
            isExtern:      $isExtern,
            isInline:      $isInline,
            isVirtual:     $isVirtual,
            pointerDepth:  $pointerDepth,
            isReference:   $isReference,
            templateParam: $templateParam,
            className:     $className,
            namespacePath: $namespacePath,
        ))->setLocation($tok->line, $tok->column, $tok->file);
    }

    /**
     * Heuristic: are we in a context where `<` is most likely a comparison
     * rather than the start of a template argument list?
     *
     * We assume template context when the immediately preceding token is an
     * identifier and we are NOT already parsing a template argument list
     * at depth 0.  When templateDepth > 0 we are already inside angle brackets
     * so `<` is relational.
     */
    private function isComparisonContext(): bool
    {
        if ($this->templateDepth > 0) {
            return true;
        }
        // If previous token is an identifier, it's likely a template.
        $prev = $this->peek(-1);
        if ($prev === null) {
            return false;
        }
        return $prev->type !== TokenType::Identifier;
    }

    private function parseClassOrStructDeclaration(): ClassDeclaration
    {
        $tok      = $this->current();
        $isStruct = $tok->type === TokenType::Struct || $tok->type === TokenType::Union;
        $isUnion  = $tok->type === TokenType::Union;
        $this->advance();

        // Skip __attribute__((...)) that may appear between the keyword and the tag name:
        // `struct __attribute__((__may_alias__)) sockaddr { ... }`
        $this->skipGccExtensions();

        $name = 'anonymous';
        if ($this->check(TokenType::Identifier)) {
            $name = $this->advance()->value;
        }

        $this->skipAttribute();

        // Register as a known type name for disambiguation.
        $this->typeNames[$name] = true;

        // Forward declaration: `class Foo;`
        if ($this->check(TokenType::Semicolon)) {
            $this->advance();
            return (new ClassDeclaration(
                name:          $name,
                members:       [],
                isStruct:      $isStruct,
                isForwardDecl: true,
                isUnion:       $isUnion,
            ))->setLocation($tok->line, $tok->column, $tok->file);
        }

        // Inheritance: `class Foo : public Bar`
        $baseClass  = null;
        $baseAccess = $isStruct ? 'public' : 'private'; // default access differs
        if ($this->check(TokenType::Colon)) {
            $this->advance();
            if ($this->match(TokenType::Public, TokenType::Private, TokenType::Protected)) {
                $baseAccess = $this->advance()->value;
            }
            $baseClass = $this->expect(TokenType::Identifier)->value;
        }

        $this->expect(TokenType::LeftBrace);
        $members = $this->parseClassBody($isStruct);
        $this->expect(TokenType::RightBrace);

        // Handle `struct/union { ... } varname, *ptr;` — variable declaration after body.
        // Skip any trailing qualifiers or pointers, then variable name(s), before `;`.
        while ($this->check(TokenType::Identifier) || $this->check(TokenType::Star)
            || $this->check(TokenType::Const) || $this->check(TokenType::Volatile)) {
            if ($this->check(TokenType::Identifier)) {
                $this->advance(); // consume variable name
                // Array declarator: `var[N]`
                while ($this->check(TokenType::LeftBracket)) {
                    $this->advance();
                    if (!$this->check(TokenType::RightBracket)) {
                        $this->parseExpression();
                    }
                    $this->expect(TokenType::RightBracket);
                }
                if ($this->check(TokenType::Comma)) {
                    $this->advance(); // consume ','
                    continue;
                }
                break;
            }
            $this->advance(); // consume *, const, volatile
        }
        $this->expect(TokenType::Semicolon);

        return (new ClassDeclaration(
            name:       $name,
            members:    $members,
            isStruct:   $isStruct,
            baseClass:  $baseClass,
            baseAccess: $baseAccess,
            isUnion:    $isUnion,
        ))->setLocation($tok->line, $tok->column, $tok->file);
    }

    /** @return Node[] */
    private function parseClassBody(bool $isStruct): array
    {
        $members = [];

        // Default access: public for struct, private for class.
        $currentAccess = $isStruct ? 'public' : 'private';

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::EOF)) {
            if ($this->match(TokenType::Public, TokenType::Private, TokenType::Protected)) {
                $accessTok     = $this->advance();
                $currentAccess = $accessTok->value;
                $this->expect(TokenType::Colon);
                $members[] = (new AccessSpecifier($currentAccess))
                    ->setLocation($accessTok->line, $accessTok->column, $accessTok->file);
                continue;
            }

            try {
                $members[] = $this->parseDeclaration();
            } catch (CompileError $e) {
                $this->synchronize();
            }
        }

        return $members;
    }

    private function parseEnumDeclaration(): EnumDeclaration
    {
        $tok = $this->current();
        $this->expect(TokenType::Enum);

        // enum class / enum struct — scoped enum.
        $isScoped = false;
        if ($this->match(TokenType::Class_, TokenType::Struct)) {
            $this->advance();
            $isScoped = true;
        }

        $name = 'anonymous';
        if ($this->check(TokenType::Identifier)) {
            $name = $this->advance()->value;
        }

        $this->typeNames[$name] = true;

        // Underlying type: `enum Foo : unsigned char { … }`
        $underlyingType = null;
        if ($this->check(TokenType::Colon)) {
            $this->advance();
            $underlyingType = $this->parseType();
        }

        $this->expect(TokenType::LeftBrace);
        $entries = $this->parseEnumBody();
        $this->expect(TokenType::RightBrace);
        $this->expect(TokenType::Semicolon);

        return (new EnumDeclaration(
            name:           $name,
            entries:        $entries,
            isScoped:       $isScoped,
            underlyingType: $underlyingType,
        ))->setLocation($tok->line, $tok->column, $tok->file);
    }

    /** @return EnumEntry[] */
    private function parseEnumBody(): array
    {
        $entries = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::EOF)) {
            $entTok  = $this->current();
            $entName = $this->expect(TokenType::Identifier)->value;
            $entVal  = null;
            if ($this->check(TokenType::Assign)) {
                $this->advance();
                $entVal = $this->parseExpression(Precedence::COMMA);
            }
            $entries[] = (new EnumEntry($entName, $entVal))
                ->setLocation($entTok->line, $entTok->column, $entTok->file);

            if ($this->check(TokenType::Comma)) {
                $this->advance();
                // Allow trailing comma.
            }
        }

        return $entries;
    }

    private function parseNamespaceDeclaration(): NamespaceDeclaration
    {
        $tok = $this->current();
        $this->expect(TokenType::Namespace);

        $name = 'anonymous';
        if ($this->check(TokenType::Identifier)) {
            $name = $this->advance()->value;
        }

        $this->expect(TokenType::LeftBrace);
        $decls = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::EOF)) {
            try {
                $decls[] = $this->parseDeclaration();
            } catch (CompileError $e) {
                $this->synchronize();
            }
        }

        $this->expect(TokenType::RightBrace);

        return (new NamespaceDeclaration($name, $decls))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseTemplateDeclaration(): TemplateDeclaration
    {
        $tok = $this->current();
        $this->expect(TokenType::Template);
        $this->expect(TokenType::Less);

        $params = $this->parseTemplateParameterList();

        $this->expect(TokenType::Greater);

        // Register template type parameters as type names so they parse correctly
        // inside the template body (e.g., `T* ptr` parses as pointer, not multiplication).
        $addedTypeNames = [];
        foreach ($params as $p) {
            if ($p->isTypename && $p->name !== '' && !isset($this->typeNames[$p->name])) {
                $this->typeNames[$p->name] = true;
                $addedTypeNames[] = $p->name;
            }
        }

        $decl = $this->parseDeclaration();

        // Remove template parameter type names to avoid polluting outer scope.
        foreach ($addedTypeNames as $name) {
            unset($this->typeNames[$name]);
        }

        return (new TemplateDeclaration($params, $decl))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    /** @return TemplateParameter[] */
    private function parseTemplateParameterList(): array
    {
        $params = [];

        if ($this->check(TokenType::Greater)) {
            return $params;
        }

        do {
            $paramTok   = $this->current();
            $isTypename = true;
            $paramName  = '';
            $defaultType = null;

            if ($this->match(TokenType::Typename, TokenType::Class_)) {
                $isTypename = ($this->advance()->type === TokenType::Typename);
            } else {
                // Non-type template parameter — parse and discard the type.
                $this->parseType();
                $isTypename = false;
            }

            if ($this->check(TokenType::Identifier)) {
                $paramName = $this->advance()->value;
            }

            if ($this->check(TokenType::Assign)) {
                $this->advance();
                $defaultType = $this->parseType();
            }

            $params[] = (new TemplateParameter($paramName, $isTypename, $defaultType))
                ->setLocation($paramTok->line, $paramTok->column, $paramTok->file);

        } while ($this->check(TokenType::Comma) && $this->advance() !== null);

        return $params;
    }

    private function parseTypedefDeclaration(): Node
    {
        $tok = $this->current();
        $this->expect(TokenType::Typedef);

        // Handle `typedef struct { ... } Name;` and `typedef enum { ... } Name;`
        if ($this->match(TokenType::Struct, TokenType::Union, TokenType::Enum)) {
            $kindTok = $this->current();
            $isEnum = $kindTok->type === TokenType::Enum;
            $isUnion = $kindTok->type === TokenType::Union;

            // Check if this is `typedef struct/union/enum Name ...` or `typedef struct/union/enum { ... } Name`
            $this->advance(); // consume struct/union/enum

            $this->skipAttribute();

            $tagName = null;
            if ($this->check(TokenType::Identifier) && !$this->isAttributeIdentifier($this->current()->value)) {
                $nextTok = $this->peek(1);
                if ($nextTok !== null && ($nextTok->type === TokenType::LeftBrace || $this->isAttributeIdentifier($nextTok->value))) {
                    // `typedef struct Tag { ... } Alias;` or `typedef struct Tag __attribute__(...) { ... } Alias;`
                    $tagName = $this->advance()->value;
                    $this->skipAttribute();
                } elseif ($nextTok !== null && $nextTok->type !== TokenType::LeftBrace) {
                    // `typedef struct ExistingType *Alias, *Alias2;` — simple typedef(s)
                    $rawName = $this->advance()->value;
                    $prefix = $isEnum ? 'enum' : ($isUnion ? 'union' : 'struct');
                    $typedefNodes = [];

                    do {
                        $type = new TypeNode(baseName: "{$prefix}:{$rawName}");
                        $type->className = $rawName;
                        while ($this->check(TokenType::Star)) {
                            $this->advance();
                            $type->pointerDepth++;
                        }
                        while ($this->check(TokenType::Const) || $this->check(TokenType::Volatile) || $this->check(TokenType::Restrict)) {
                            if ($this->current()->type === TokenType::Const) {
                                $type->isConst = true;
                            }
                            $this->advance();
                        }
                        $alias = $this->expect(TokenType::Identifier)->value;
                        $this->skipGccExtensions();

                        // Array typedef: `typedef struct Tag Alias[N];`
                        if ($this->check(TokenType::LeftBracket)) {
                            $arrSize = null;
                            while ($this->check(TokenType::LeftBracket)) {
                                $this->advance();
                                $dimSize = null;
                                if (!$this->check(TokenType::RightBracket)) {
                                    $sizeExpr = $this->parseExpression();
                                    if ($sizeExpr instanceof IntLiteral) {
                                        $dimSize = $sizeExpr->value;
                                    }
                                }
                                $this->expect(TokenType::RightBracket);
                                if ($dimSize !== null) {
                                    $arrSize = $arrSize !== null ? $arrSize * $dimSize : $dimSize;
                                }
                            }
                            $arrType = TypeNode::array($type, $arrSize);
                            $this->typeNames[$alias] = true;
                            $typedefNodes[] = (new TypedefDeclaration($arrType, $alias))
                                ->setLocation($tok->line, $tok->column, $tok->file);
                        } else {
                            $this->typeNames[$alias] = true;
                            $typedefNodes[] = (new TypedefDeclaration($type, $alias))
                                ->setLocation($tok->line, $tok->column, $tok->file);
                        }
                    } while ($this->check(TokenType::Comma) && $this->advance() !== null);

                    $this->expect(TokenType::Semicolon);
                    if (count($typedefNodes) === 1) {
                        return $typedefNodes[0];
                    }
                    return (new BlockStatement($typedefNodes))
                        ->setLocation($tok->line, $tok->column, $tok->file);
                }
            }

            if ($this->check(TokenType::LeftBrace)) {
                if ($isEnum) {
                    // Parse enum body inline
                    $this->expect(TokenType::LeftBrace);
                    $entries = $this->parseEnumBody();
                    $this->expect(TokenType::RightBrace);
                    $this->skipGccExtensions();

                    // Parse comma-separated aliases: `typedef enum { ... } Name, *NamePtr;`
                    $typedefNodes = [];
                    $alias = $this->expect(TokenType::Identifier)->value;
                    $this->skipGccExtensions();
                    $this->typeNames[$alias] = true;

                    $enumName = $tagName ?? $alias;
                    $enumDecl = (new EnumDeclaration($enumName, $entries, false, null))
                        ->setLocation($kindTok->line, $kindTok->column, $kindTok->file);

                    while ($this->check(TokenType::Comma)) {
                        $this->advance();
                        $extraType = new TypeNode(baseName: "enum:{$enumName}");
                        $extraType->className = $enumName;
                        while ($this->check(TokenType::Star)) {
                            $this->advance();
                            $extraType->pointerDepth++;
                        }
                        $extraAlias = $this->expect(TokenType::Identifier)->value;
                        $this->skipGccExtensions();
                        $this->typeNames[$extraAlias] = true;
                        $typedefNodes[] = (new TypedefDeclaration($extraType, $extraAlias))
                            ->setLocation($tok->line, $tok->column, $tok->file);
                    }

                    $this->expect(TokenType::Semicolon);

                    if (empty($typedefNodes)) {
                        return $enumDecl;
                    }
                    array_unshift($typedefNodes, $enumDecl);
                    return (new BlockStatement($typedefNodes))
                        ->setLocation($tok->line, $tok->column, $tok->file);
                } else {
                    // Parse struct/union body inline
                    $this->expect(TokenType::LeftBrace);
                    $members = $this->parseClassBody(true);
                    $this->expect(TokenType::RightBrace);
                    $this->skipGccExtensions();

                    // Handle pointer: `typedef struct { ... }* FooPtr;`
                    $ptrDepth = 0;
                    while ($this->check(TokenType::Star)) {
                        $this->advance();
                        $ptrDepth++;
                    }

                    $alias = $this->expect(TokenType::Identifier)->value;
                    // Skip __attribute__((...)) after alias name: `typedef union { ... } Name __attribute__((...));`
                    $this->skipGccExtensions();
                    $this->typeNames[$alias] = true;

                    // Create a ClassDeclaration with the tag name (or alias if no tag)
                    $structName = $tagName ?? $alias;
                    $typedefAlias = ($tagName !== null && $tagName !== $alias) ? $alias : null;

                    $classDecl = (new ClassDeclaration(
                        name: $structName,
                        members: $members,
                        isStruct: true,
                        isUnion: $isUnion,
                        typedefAlias: $ptrDepth > 0 ? null : $typedefAlias,
                    ))->setLocation($kindTok->line, $kindTok->column, $kindTok->file);

                    $decls = [$classDecl];

                    if ($ptrDepth > 0) {
                        $targetType = new TypeNode(baseName: "struct:{$structName}", pointerDepth: $ptrDepth);
                        $targetType->className = $structName;
                        $decls[] = (new TypedefDeclaration($targetType, $alias))
                            ->setLocation($tok->line, $tok->column, $tok->file);
                    }

                    // Comma-separated aliases: `typedef struct { ... } Name, *NamePtr;`
                    while ($this->check(TokenType::Comma)) {
                        $this->advance();
                        $extraType = new TypeNode(baseName: "struct:{$structName}");
                        $extraType->className = $structName;
                        while ($this->check(TokenType::Star)) {
                            $this->advance();
                            $extraType->pointerDepth++;
                        }
                        $extraAlias = $this->expect(TokenType::Identifier)->value;
                        $this->skipGccExtensions();
                        $this->typeNames[$extraAlias] = true;
                        $decls[] = (new TypedefDeclaration($extraType, $extraAlias))
                            ->setLocation($tok->line, $tok->column, $tok->file);
                    }

                    $this->expect(TokenType::Semicolon);

                    if (count($decls) === 1) {
                        return $classDecl;
                    }
                    return (new BlockStatement($decls))
                        ->setLocation($tok->line, $tok->column, $tok->file);
                }
            }
        }

        // Simple typedef: `typedef int Integer;`
        // or function pointer typedef: `typedef int (*Comparator)(int, int);`
        $type  = $this->parseType();

        // Skip GCC __attribute__ between type and declarator name, e.g.:
        // typedef _Complex float __attribute__((mode(TC))) fftwq_complex;
        $this->skipGccExtensions();

        // Function pointer typedef: typedef <ret> (*<alias>)(<params>);
        // Supports comma-separated aliases sharing the same base return type:
        //   typedef void *(*A)(size_t), (*B)(void *), *(*C)(void *, size_t);
        if ($this->check(TokenType::LeftParen)
            && $this->peek(1)?->type === TokenType::Star
        ) {
            $typedefNodes = [];
            $baseType = $type;

            do {
                // Each declarator may add pointer depth to the return type.
                $retType = clone $baseType;
                $retType->pointerDepth = $baseType->pointerDepth;

                // Consume leading '*' stars (pointer return type modifiers).
                while ($this->check(TokenType::Star)) {
                    $this->advance();
                    $retType->pointerDepth++;
                    while ($this->check(TokenType::Const) || $this->check(TokenType::Restrict) || $this->check(TokenType::Volatile)) {
                        $this->advance();
                    }
                }

                $this->advance(); // consume '('
                $this->advance(); // consume '*'
                $alias = $this->expect(TokenType::Identifier)->value;
                $this->expect(TokenType::RightParen);
                $this->expect(TokenType::LeftParen);
                $paramTypes = [];
                if (!$this->check(TokenType::RightParen)) {
                    if ($this->check(TokenType::Void) && $this->peek(1)?->type === TokenType::RightParen) {
                        $this->advance(); // consume 'void'
                    } else {
                        do {
                            if ($this->check(TokenType::Ellipsis)) {
                                $this->advance();
                                break;
                            }
                            $pType = $this->parseType();
                            // Skip optional parameter name
                            if ($this->check(TokenType::Identifier)
                                && !$this->match(TokenType::Comma, TokenType::RightParen)
                            ) {
                                $this->advance();
                            }
                            // Array parameter: `In[]` or `In[size]`
                            while ($this->check(TokenType::LeftBracket)) {
                                $this->advance();
                                if (!$this->check(TokenType::RightBracket)) {
                                    $this->parseExpression();
                                }
                                $this->expect(TokenType::RightBracket);
                                $pType->pointerDepth++;
                            }
                            $paramTypes[] = $pType;
                        } while ($this->match(TokenType::Comma) && $this->advance() !== null && !$this->check(TokenType::RightParen));
                    }
                }
                $this->expect(TokenType::RightParen);
                // Skip trailing __attribute__((...)) between declarators or before ';'
                $this->skipGccExtensions();

                $fpType = TypeNode::functionPointer($retType, $paramTypes);
                $this->typeNames[$alias] = true;
                $typedefNodes[] = (new TypedefDeclaration($fpType, $alias))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            } while ($this->check(TokenType::Comma) && $this->advance() !== null);

            $this->expect(TokenType::Semicolon);

            if (count($typedefNodes) === 1) {
                return $typedefNodes[0];
            }
            return (new BlockStatement($typedefNodes))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        $alias = $this->expect(TokenType::Identifier)->value;

        // Function type typedef: `typedef T name(params);`
        // Treat as equivalent to `typedef T (*name)(params)` — a function pointer typedef.
        if ($this->check(TokenType::LeftParen)) {
            $this->advance(); // consume '('
            $paramTypes = [];
            if (!$this->check(TokenType::RightParen)) {
                if ($this->check(TokenType::Void) && $this->peek(1)?->type === TokenType::RightParen) {
                    $this->advance(); // consume 'void'
                } else {
                    do {
                        if ($this->check(TokenType::Ellipsis)) {
                            $this->advance(); // consume '...'
                            break;
                        }
                        $paramTypes[] = $this->parseType();
                        // Skip optional parameter name
                        if ($this->check(TokenType::Identifier)
                            && !$this->match(TokenType::Comma, TokenType::RightParen)
                        ) {
                            $this->advance();
                        }
                    } while ($this->match(TokenType::Comma) && $this->advance() !== null && !$this->check(TokenType::RightParen));
                }
            }
            $this->expect(TokenType::RightParen);
            $this->expect(TokenType::Semicolon);

            $fpType = TypeNode::functionPointer($type, $paramTypes);
            $this->typeNames[$alias] = true;
            return (new TypedefDeclaration($fpType, $alias))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        // Array typedef: `typedef T name[size];` or multi-dimensional `typedef T name[N][M];`
        if ($this->check(TokenType::LeftBracket)) {
            $arrSize = null;
            while ($this->check(TokenType::LeftBracket)) {
                $this->advance(); // consume '['
                $dimSize = null;
                if (!$this->check(TokenType::RightBracket)) {
                    $sizeExpr = $this->parseExpression();
                    if ($sizeExpr instanceof IntLiteral) {
                        $dimSize = $sizeExpr->value;
                    }
                }
                $this->expect(TokenType::RightBracket);
                // Flatten multi-dimensional: multiply sizes together
                if ($dimSize !== null) {
                    $arrSize = $arrSize !== null ? $arrSize * $dimSize : $dimSize;
                }
            }
            $this->expect(TokenType::Semicolon);

            $arrType = TypeNode::array($type, $arrSize);
            $this->typeNames[$alias] = true;
            return (new TypedefDeclaration($arrType, $alias))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        // Skip any trailing GCC extensions before `;`, e.g. __attribute__((__mode__(__word__)))
        $this->skipGccExtensions();

        // Multiple declarators: `typedef int XrmQuark, *XrmQuarkList;`
        if ($this->check(TokenType::Comma)) {
            $typedefNodes = [];
            $this->typeNames[$alias] = true;
            $typedefNodes[] = (new TypedefDeclaration($type, $alias))
                ->setLocation($tok->line, $tok->column, $tok->file);

            while ($this->check(TokenType::Comma)) {
                $this->advance();
                $extraType = clone $type;
                $extraType->pointerDepth = 0;
                while ($this->check(TokenType::Star)) {
                    $this->advance();
                    $extraType->pointerDepth++;
                }
                $extraAlias = $this->expect(TokenType::Identifier)->value;
                $this->skipGccExtensions();
                $this->typeNames[$extraAlias] = true;
                $typedefNodes[] = (new TypedefDeclaration($extraType, $extraAlias))
                    ->setLocation($tok->line, $tok->column, $tok->file);
            }

            $this->expect(TokenType::Semicolon);
            if (count($typedefNodes) === 1) {
                return $typedefNodes[0];
            }
            return (new BlockStatement($typedefNodes))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        $this->expect(TokenType::Semicolon);

        $this->typeNames[$alias] = true;

        return (new TypedefDeclaration($type, $alias))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseUsingDeclaration(): UsingDeclaration
    {
        $tok = $this->current();
        $this->expect(TokenType::Using);

        $isNamespace = false;

        if ($this->check(TokenType::Namespace)) {
            $this->advance();
            $isNamespace = true;
        }

        // Collect the fully qualified name: `std::string`
        $nameParts = [$this->expect(TokenType::Identifier)->value];
        while ($this->check(TokenType::DoubleColon) && $this->peek(1)->type === TokenType::Identifier) {
            $this->advance();
            $nameParts[] = $this->advance()->value;
        }
        $name = implode('::', $nameParts);

        $this->expect(TokenType::Semicolon);

        return (new UsingDeclaration($name, $isNamespace))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseBlock(): BlockStatement
    {
        $tok   = $this->current();
        $stmts = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::EOF)) {
            try {
                $stmts[] = $this->parseStatement();
            } catch (CompileError $e) {
                $this->synchronize();
            }
        }

        $this->expect(TokenType::RightBrace);

        return (new BlockStatement($stmts))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseStatement(): Node
    {
        $tok = $this->current();

        return match ($tok->type) {
            TokenType::LeftBrace    => $this->parseInnerBlock(),
            TokenType::If           => $this->parseIfStatement(),
            TokenType::While        => $this->parseWhileStatement(),
            TokenType::Do           => $this->parseDoWhileStatement(),
            TokenType::For          => $this->parseForStatement(),
            TokenType::Switch       => $this->parseSwitchStatement(),
            TokenType::Return       => $this->parseReturnStatement(),
            TokenType::Break        => $this->parseBreakStatement(),
            TokenType::Continue     => $this->parseContinueStatement(),
            TokenType::Goto         => $this->parseGotoStatement(),
            TokenType::Semicolon    => $this->parseEmptyStatement(),
            default                 => $this->parseExpressionOrDeclarationStatement(),
        };
    }

    private function parseInnerBlock(): BlockStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::LeftBrace);
        $block = $this->parseBlock();
        return $block->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseIfStatement(): IfStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::If);
        $this->expect(TokenType::LeftParen);
        $cond = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $then = $this->parseStatement();

        $else = null;
        if ($this->check(TokenType::Else)) {
            $this->advance();
            $else = $this->parseStatement();
        }

        return (new IfStatement($cond, $then, $else))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseWhileStatement(): WhileStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::While);
        $this->expect(TokenType::LeftParen);
        $cond = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $body = $this->parseStatement();

        return (new WhileStatement($cond, $body))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseDoWhileStatement(): DoWhileStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::Do);
        $body = $this->parseStatement();
        $this->expect(TokenType::While);
        $this->expect(TokenType::LeftParen);
        $cond = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $this->expect(TokenType::Semicolon);

        return (new DoWhileStatement($body, $cond))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseForStatement(): ForStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::For);
        $this->expect(TokenType::LeftParen);

        // Init clause — may be a declaration, expression, or empty.
        $init = null;
        if (!$this->check(TokenType::Semicolon)) {
            if ($this->isTypeStart()) {
                $initType = $this->parseType();
                $initName = $this->expect(TokenType::Identifier)->value;
                $init     = $this->parseVarDeclaration($initType, $initName);
                // parseVarDeclaration already consumed the semicolon.
            } else {
                $init = $this->parseExpressionStatement();
            }
        } else {
            $this->advance();
        }

        $cond = null;
        if (!$this->check(TokenType::Semicolon)) {
            $cond = $this->parseExpression();
        }
        $this->expect(TokenType::Semicolon);

        $update = null;
        if (!$this->check(TokenType::RightParen)) {
            $update = $this->parseExpression();
        }
        $this->expect(TokenType::RightParen);

        $body = $this->parseStatement();

        return (new ForStatement($init, $cond, $update, $body))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseSwitchStatement(): SwitchStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::Switch);
        $this->expect(TokenType::LeftParen);
        $expr = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        $this->expect(TokenType::LeftBrace);

        $cases = [];
        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::EOF)) {
            $caseTok   = $this->current();
            $isDefault = false;
            $caseVal   = null;

            if ($this->check(TokenType::Case)) {
                $this->advance();
                $caseVal = $this->parseExpression();
                $this->expect(TokenType::Colon);
            } elseif ($this->check(TokenType::Default)) {
                $this->advance();
                $this->expect(TokenType::Colon);
                $isDefault = true;
            } else {
                break;
            }

            $caseStmts = [];
            while (
                !$this->check(TokenType::Case) &&
                !$this->check(TokenType::Default) &&
                !$this->check(TokenType::RightBrace) &&
                !$this->check(TokenType::EOF)
            ) {
                $caseStmts[] = $this->parseStatement();
            }

            $cases[] = (new CaseClause($caseVal, $caseStmts, $isDefault))
                ->setLocation($caseTok->line, $caseTok->column, $caseTok->file);
        }

        $this->expect(TokenType::RightBrace);

        return (new SwitchStatement($expr, $cases))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseReturnStatement(): ReturnStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::Return);
        $expr = null;
        if (!$this->check(TokenType::Semicolon)) {
            $expr = $this->parseExpression();
        }
        $this->expect(TokenType::Semicolon);
        return (new ReturnStatement($expr))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseBreakStatement(): BreakStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::Break);
        $this->expect(TokenType::Semicolon);
        return (new BreakStatement())->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseContinueStatement(): ContinueStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::Continue);
        $this->expect(TokenType::Semicolon);
        return (new ContinueStatement())->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseGotoStatement(): GotoStatement
    {
        $tok = $this->current();
        $this->expect(TokenType::Goto);
        $label = $this->expect(TokenType::Identifier)->value;
        $this->expect(TokenType::Semicolon);
        return (new GotoStatement($label))->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseEmptyStatement(): ExpressionStatement
    {
        $tok = $this->current();
        $this->advance();
        // Represent as an empty expression statement (void-like).
        $noop = (new IntLiteral(0))->setLocation($tok->line, $tok->column, $tok->file);
        return (new ExpressionStatement($noop))->setLocation($tok->line, $tok->column, $tok->file);
    }

    /**
     * Determine whether the current position starts a declaration statement
     * (in a function body) or an expression statement, and dispatch accordingly.
     *
     * Special case: `Identifier :` may be a label statement.
     */
    private function parseExpressionOrDeclarationStatement(): Node
    {
        $this->skipGccExtensions();

        // After skipping GCC extensions (e.g. __attribute__((fallthrough))),
        // there may be nothing left but a semicolon.
        if ($this->check(TokenType::Semicolon)) {
            $tok = $this->advance();
            return (new ExpressionStatement(
                (new IntLiteral(0))->setLocation($tok->line, $tok->column, $tok->file)
            ))->setLocation($tok->line, $tok->column, $tok->file);
        }

        // Label: `foo:`
        if (
            $this->check(TokenType::Identifier) &&
            $this->peek(1)->type === TokenType::Colon
        ) {
            return $this->parseLabelStatement();
        }

        // Local typedef inside a function body.
        if ($this->check(TokenType::Typedef)) {
            return $this->parseTypedefDeclaration();
        }

        // Local struct/union/enum definition inside a function body.
        if (
            ($this->check(TokenType::Struct) || $this->check(TokenType::Union) || $this->check(TokenType::Enum))
            && $this->peek(1)->type !== TokenType::Star
        ) {
            $next = $this->peek(1);
            // struct Foo { ... } → definition, struct { ... } → anonymous
            if ($next->type === TokenType::LeftBrace) {
                return $this->parseClassOrStructDeclaration();
            }
            if ($next->type === TokenType::Identifier) {
                $afterName = $this->peek(2);
                if ($afterName->type === TokenType::LeftBrace || $afterName->type === TokenType::Semicolon) {
                    return $this->parseClassOrStructDeclaration();
                }
            }
        }

        if ($this->isTypeStart()) {
            $type = $this->parseType();
            // Skip trailing qualifiers: `struct { ... } const name`
            while ($this->check(TokenType::Const) || $this->check(TokenType::Volatile)
                || $this->check(TokenType::Restrict)) {
                if ($this->check(TokenType::Const)) {
                    $type->isConst = true;
                }
                $this->advance();
            }
            $name = $this->expect(TokenType::Identifier)->value;
            return $this->parseVarDeclaration($type, $name);
        }

        return $this->parseExpressionStatement();
    }

    private function parseLabelStatement(): LabelStatement
    {
        $tok   = $this->current();
        $label = $this->advance()->value; // identifier
        $this->advance(); // colon
        $stmt  = $this->parseStatement();
        return (new LabelStatement($label, $stmt))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseExpressionStatement(): ExpressionStatement
    {
        $tok  = $this->current();
        $expr = $this->parseExpression();
        $this->expect(TokenType::Semicolon);
        return (new ExpressionStatement($expr))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    /**
     * Core Pratt parser entry point.
     *
     * @param int $minPrecedence  Only consume infix operators with binding power
     *                            strictly greater than this value.
     */
    private function parseExpression(int $minPrecedence = 0): Node
    {
        $left = $this->parsePrefixExpression();

        while (true) {
            $tok    = $this->current();
            $prec   = Precedence::getInfixPrecedence($tok->type);

            if ($prec <= $minPrecedence) {
                break;
            }

            $left = $this->parseInfixExpression($left, $prec);
        }

        return $left;
    }

    /**
     * Parse an expression that can start at the current position (null-denotation).
     * Handles literals, identifiers, unary operators, grouping `(expr)`, casts,
     * sizeof, new, delete, this.
     */
    private function parsePrefixExpression(): Node
    {
        $tok = $this->current();

        switch ($tok->type) {
            case TokenType::IntLiteral:
                $this->advance();
                return (new IntLiteral(intval($tok->value, 0)))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::FloatLiteral:
                $this->advance();
                return (new FloatLiteral((float) $tok->value))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::CharLiteral:
                $this->advance();
                $char = $tok->value;
                $ord  = strlen($char) > 0 ? ord($char[0]) : 0;
                return (new CharLiteralNode($char, $ord))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::StringLiteral:
                $this->advance();
                $strVal = $tok->value;
                while ($this->check(TokenType::StringLiteral)) {
                    $strVal .= $this->current()->value;
                    $this->advance();
                }
                return (new StringLiteralNode($strVal))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::True_:
                $this->advance();
                return (new BoolLiteral(true))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::False_:
                $this->advance();
                return (new BoolLiteral(false))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::Nullptr:
                $this->advance();
                return (new NullptrLiteral())
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::This:
                $this->advance();
                return (new ThisExpr())
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::Identifier:
                // GCC built-in identifiers that act as string literals.
                if ($tok->value === '__func__' || $tok->value === '__PRETTY_FUNCTION__' || $tok->value === '__FUNCTION__') {
                    $this->advance();
                    return (new StringLiteralNode(''))
                        ->setLocation($tok->line, $tok->column, $tok->file);
                }
                // __extension__ in expression context: skip it and parse the actual expression.
                if ($tok->value === '__extension__') {
                    $this->advance();
                    return $this->parsePrefixExpression();
                }
                return $this->parseIdentifierExpression();

            case TokenType::Exclamation:
            case TokenType::Tilde:
            case TokenType::Minus:
            case TokenType::Plus:
                $this->advance();
                $operand = $this->parseExpression(Precedence::PREFIX - 1);
                return (new UnaryExpr($tok->value, $operand, true))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::PlusPlus:
            case TokenType::MinusMinus:
                $this->advance();
                $operand = $this->parseExpression(Precedence::PREFIX - 1);
                return (new UnaryExpr($tok->value, $operand, true))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::Star:
                $this->advance();
                $operand = $this->parseExpression(Precedence::PREFIX - 1);
                return (new UnaryExpr('*', $operand, true))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::Ampersand:
                $this->advance();
                $operand = $this->parseExpression(Precedence::PREFIX - 1);
                return (new UnaryExpr('&', $operand, true))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            // Grouping `(expr)` or C-style cast `(type)expr`
            case TokenType::LeftParen:
                return $this->parseParenOrCast();

            case TokenType::Sizeof:
                return $this->parseSizeof();

            case TokenType::New:
                return $this->parseNewExpression();

            case TokenType::Delete:
                return $this->parseDeleteExpression();

            case TokenType::StaticCast:
            case TokenType::DynamicCast:
            case TokenType::ReinterpretCast:
            case TokenType::ConstCast:
                return $this->parseNamedCast($tok);

            // Scope resolution with empty left side: `::globalFunc()`
            case TokenType::DoubleColon:
                $this->advance();
                $name = $this->expect(TokenType::Identifier)->value;
                return (new ScopeResolutionExpr(null, $name))
                    ->setLocation($tok->line, $tok->column, $tok->file);

            case TokenType::LeftBrace:
                return $this->parseInitializerListExpr();

            default:
                $this->error("Unexpected token '{$tok->value}' in expression", $tok);
        }
    }

    /**
     * Parse an infix/postfix expression given the already-parsed left-hand side.
     *
     * @param Node $left        Left-hand side already parsed.
     * @param int  $precedence  Binding power of the infix operator being consumed.
     */
    private function parseInfixExpression(Node $left, int $precedence): Node
    {
        $tok = $this->current();

        if ($tok->type === TokenType::Comma) {
            $this->advance();
            $right = $this->parseExpression(Precedence::COMMA);
            $exprs = [];
            if ($left instanceof CommaExpr) {
                $exprs = $left->expressions;
            } else {
                $exprs[] = $left;
            }
            if ($right instanceof CommaExpr) {
                $exprs = array_merge($exprs, $right->expressions);
            } else {
                $exprs[] = $right;
            }
            return (new CommaExpr($exprs))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        if ($tok->type->isAssignment()) {
            $this->advance();
            // Right-associative: parse RHS with (precedence - 1) so that
            // chained assignments like `a = b = c` work correctly.
            $right = $this->parseExpression($precedence - 1);
            return (new AssignExpr($left, $tok->value, $right))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        if ($tok->type === TokenType::Question) {
            $this->advance();
            $trueExpr = $this->parseExpression(0);
            $this->expect(TokenType::Colon);
            $falseExpr = $this->parseExpression(Precedence::TERNARY - 1);
            return (new TernaryExpr($left, $trueExpr, $falseExpr))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        if ($this->isBinaryOperator($tok->type)) {
            $this->advance();
            $nextPrec = Precedence::isRightAssociative($tok->type)
                ? $precedence - 1
                : $precedence;
            $right = $this->parseExpression($nextPrec);
            return (new BinaryExpr($left, $tok->value, $right))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        // Postfix ++/--
        if ($tok->type === TokenType::PlusPlus || $tok->type === TokenType::MinusMinus) {
            $this->advance();
            return (new UnaryExpr($tok->value, $left, false))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        if ($tok->type === TokenType::LeftParen) {
            $this->advance();
            $args = $this->parseArgumentList();
            $this->expect(TokenType::RightParen);
            return (new CallExpr($left, $args))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        if ($tok->type === TokenType::LeftBracket) {
            $this->advance();
            $index = $this->parseExpression();
            $this->expect(TokenType::RightBracket);
            return (new ArrayAccessExpr($left, $index))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        // Member access `.` or `->`
        if ($tok->type === TokenType::Dot || $tok->type === TokenType::Arrow) {
            $this->advance();
            $isArrow = ($tok->type === TokenType::Arrow);

            if ($this->check(TokenType::Operator)) {
                // e.g. `obj.operator+()`
                $this->advance();
                $symbol = $this->parseOperatorSymbol();
                $memberName = 'operator' . $symbol;
            } else {
                $memberName = $this->expectIdentifierOrKeyword();
            }

            return (new MemberAccessExpr($left, $memberName, $isArrow))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        if ($tok->type === TokenType::DoubleColon) {
            $this->advance();
            $rightName = $this->expect(TokenType::Identifier)->value;
            // Flatten nested ScopeResolutionExpr chains into a single node.
            $scope = null;
            if ($left instanceof IdentifierExpr) {
                $scope = $left->name;
            } elseif ($left instanceof ScopeResolutionExpr) {
                $scope = ($left->scope !== null ? $left->scope . '::' : '') . $left->name;
            }
            return (new ScopeResolutionExpr($scope, $rightName))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        // Should not reach here — callers only invoke this when getInfixPrecedence > 0.
        $this->error("Unexpected infix token '{$tok->value}'", $tok);
    }

    /**
     * Parse an identifier expression, handling namespace qualification and
     * template instantiation syntax (`foo<T>(args)`).
     */
    private function parseIdentifierExpression(): IdentifierExpr|ScopeResolutionExpr
    {
        $tok  = $this->current();
        $name = $this->advance()->value;

        // Namespace / scope qualification: `foo::bar`
        if ($this->check(TokenType::DoubleColon)) {
            $this->advance();
            $parts = [$name];
            while (true) {
                $part   = $this->expect(TokenType::Identifier)->value;
                $parts[] = $part;
                if ($this->check(TokenType::DoubleColon)) {
                    $this->advance();
                } else {
                    break;
                }
            }
            $rightName = array_pop($parts);
            $scope     = implode('::', $parts);
            return (new ScopeResolutionExpr($scope ?: null, $rightName))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        return (new IdentifierExpr($name))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    /**
     * Disambiguate `(expr)` (grouping) from `(type)expr` (C-style cast).
     *
     * Strategy: look ahead from the `(` token.  If the tokens immediately
     * after `(` describe a type (keyword or known-type identifier) and the
     * token after the matching `)` is *not* an operator that would make no
     * sense after a type (e.g. `+`), treat it as a cast.
     */
    private function parseParenOrCast(): Node
    {
        $tok = $this->current();
        $this->advance(); // consume `(`

        // GCC statement expression: `({ ... })` — skip the block and return 0.
        if ($this->check(TokenType::LeftBrace)) {
            $depth = 1;
            $this->advance(); // consume `{`
            while ($depth > 0 && !$this->check(TokenType::EOF)) {
                if ($this->check(TokenType::LeftBrace)) {
                    $depth++;
                } elseif ($this->check(TokenType::RightBrace)) {
                    $depth--;
                }
                $this->advance();
            }
            $this->expect(TokenType::RightParen);
            return (new IntLiteral(0))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        if ($this->looksLikeCastType()) {
            $castType = $this->parseType();

            // Function pointer cast: (void (*)(params)) expr
            // After parsing the return type, check for (*) which indicates a function pointer.
            if ($this->check(TokenType::LeftParen) && $this->peek(1)?->type === TokenType::Star) {
                $this->advance(); // consume '('
                $this->advance(); // consume '*'
                $this->expect(TokenType::RightParen); // consume ')'
                // Parse parameter types
                $this->expect(TokenType::LeftParen);
                while (!$this->check(TokenType::RightParen) && !$this->check(TokenType::EOF)) {
                    if ($this->check(TokenType::Ellipsis)) {
                        $this->advance();
                        break;
                    }
                    $this->parseType();
                    if ($this->check(TokenType::Identifier)
                        && !$this->match(TokenType::Comma, TokenType::RightParen)
                    ) {
                        $this->advance();
                    }
                    if (!$this->check(TokenType::RightParen)) {
                        $this->expect(TokenType::Comma);
                    }
                }
                $this->expect(TokenType::RightParen); // close params
                // Function pointer is always pointer-sized
                $castType->pointerDepth = 1;
            }

            $this->expect(TokenType::RightParen);
            $expr = $this->parseExpression(Precedence::PREFIX - 1);
            return (new CastExpr($castType, $expr, 'c_style'))
                ->setLocation($tok->line, $tok->column, $tok->file);
        }

        $expr = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        return $expr;
    }

    /**
     * Heuristic: does the current token position look like it begins a type?
     * Used inside `(…)` to decide cast vs grouping.
     */
    private function looksLikeCastType(): bool
    {
        $cur = $this->current();

        if ($cur->type->isTypeKeyword() || $cur->type->isTypeQualifier()) {
            return true;
        }

        if ($cur->type === TokenType::Struct || $cur->type === TokenType::Class_
            || $cur->type === TokenType::Enum || $cur->type === TokenType::Union) {
            return true;
        }

        if ($cur->type === TokenType::Identifier && isset($this->typeNames[$cur->value])) {
            // In C, a name can be both a struct tag and a function
            // (e.g., `stat` is `struct stat` and also `stat()`).
            // If the type name is immediately followed by `(`, it's a
            // function call inside grouping parens, not a cast.
            $next = $this->peek(1);
            if ($next !== null && $next->type === TokenType::LeftParen) {
                return false;
            }
            return true;
        }

        return false;
    }

    private function parseSizeof(): SizeofExpr
    {
        $tok = $this->current();
        $this->expect(TokenType::Sizeof);

        if ($this->check(TokenType::LeftParen)) {
            $this->advance();
            if ($this->looksLikeCastType()) {
                $type = $this->parseType();
                $this->expect(TokenType::RightParen);
                return (new SizeofExpr($type))->setLocation($tok->line, $tok->column, $tok->file);
            }
            $expr = $this->parseExpression();
            $this->expect(TokenType::RightParen);
            return (new SizeofExpr($expr))->setLocation($tok->line, $tok->column, $tok->file);
        }

        $expr = $this->parseExpression(Precedence::PREFIX - 1);
        return (new SizeofExpr($expr))->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseNewExpression(): NewExpr
    {
        $tok = $this->current();
        $this->expect(TokenType::New);

        $type    = $this->parseType();
        $isArray = false;
        $arrSize = null;
        $args    = [];

        if ($this->check(TokenType::LeftBracket)) {
            $this->advance();
            $isArray = true;
            if (!$this->check(TokenType::RightBracket)) {
                $arrSize = $this->parseExpression();
            }
            $this->expect(TokenType::RightBracket);
        } elseif ($this->check(TokenType::LeftParen)) {
            $this->advance();
            $args = $this->parseArgumentList();
            $this->expect(TokenType::RightParen);
        }

        return (new NewExpr($type, $args, $isArray, $arrSize))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseDeleteExpression(): DeleteExpr
    {
        $tok = $this->current();
        $this->expect(TokenType::Delete);

        $isArray = false;
        if ($this->check(TokenType::LeftBracket)) {
            $this->advance();
            $this->expect(TokenType::RightBracket);
            $isArray = true;
        }

        $expr = $this->parseExpression(Precedence::PREFIX - 1);
        return (new DeleteExpr($expr, $isArray))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    /** Parse `static_cast<Type>(expr)` and siblings. */
    private function parseNamedCast(Token $tok): CastExpr
    {
        $kind = match ($tok->type) {
            TokenType::StaticCast      => 'static_cast',
            TokenType::DynamicCast     => 'dynamic_cast',
            TokenType::ReinterpretCast => 'reinterpret_cast',
            TokenType::ConstCast       => 'const_cast',
            default                    => 'static_cast',
        };
        $this->advance();
        $this->expect(TokenType::Less);
        $this->templateDepth++;
        $targetType = $this->parseType();
        $this->templateDepth--;
        $this->expect(TokenType::Greater);
        $this->expect(TokenType::LeftParen);
        $expr = $this->parseExpression();
        $this->expect(TokenType::RightParen);
        return (new CastExpr($targetType, $expr, $kind))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseInitializerListExpr(): InitializerList
    {
        $tok = $this->current();
        return $this->parseInitializerList()->setLocation($tok->line, $tok->column, $tok->file);
    }

    private function parseInitializerList(): InitializerList
    {
        $tok = $this->current();
        $this->expect(TokenType::LeftBrace);
        $values = [];

        while (!$this->check(TokenType::RightBrace) && !$this->check(TokenType::EOF)) {
            if (
                $this->check(TokenType::Dot)
                && $this->peek(1)?->type === TokenType::Identifier
                && $this->peek(2)?->type === TokenType::Assign
            ) {
                $dotTok = $this->current();
                $this->advance();
                $fieldName = $this->advance()->value;
                $this->advance();
                $val = $this->parseExpression(Precedence::COMMA);
                $values[] = (new DesignatedInit($fieldName, $val))
                    ->setLocation($dotTok->line, $dotTok->column, $dotTok->file);
            } elseif ($this->check(TokenType::LeftBrace)) {
                // Nested initializer list for multi-dimensional arrays / nested structs.
                $values[] = $this->parseInitializerList();
            } else {
                $values[] = $this->parseExpression(Precedence::COMMA);
            }
            if ($this->check(TokenType::Comma)) {
                $this->advance();
            } else {
                break;
            }
        }

        $this->expect(TokenType::RightBrace);
        return (new InitializerList($values))
            ->setLocation($tok->line, $tok->column, $tok->file);
    }

    /** @return Node[] */
    private function parseArgumentList(): array
    {
        $args = [];

        if ($this->check(TokenType::RightParen)) {
            return $args;
        }

        do {
            $args[] = $this->parseExpression(Precedence::COMMA);
        } while ($this->check(TokenType::Comma) && $this->advance() !== null);

        return $args;
    }

    /**
     * Returns true when the current token could begin a type specifier,
     * implying that the current statement position is a declaration rather
     * than an expression statement.
     */
    private function isTypeStart(): bool
    {
        $cur = $this->current();

        if ($cur->type->isTypeKeyword() || $cur->type->isTypeQualifier()) {
            return true;
        }

        if (
            $cur->type === TokenType::Class_ ||
            $cur->type === TokenType::Struct ||
            $cur->type === TokenType::Enum ||
            $cur->type === TokenType::Union
        ) {
            return true;
        }

        // Known user-defined type name followed by an identifier (declaration)
        // or by `*` / `&` (pointer/reference variable).
        if ($cur->type === TokenType::Identifier && isset($this->typeNames[$cur->value])) {
            $next = $this->peek(1);
            return $next !== null && (
                $next->type === TokenType::Identifier ||
                $next->type === TokenType::Star ||
                $next->type === TokenType::Ampersand ||
                $next->type === TokenType::LeftBracket ||
                $next->type === TokenType::Less
            );
        }

        return false;
    }

    private function isBinaryOperator(TokenType $type): bool
    {
        return match ($type) {
            TokenType::Plus,
            TokenType::Minus,
            TokenType::Star,
            TokenType::Slash,
            TokenType::Percent,
            TokenType::Ampersand,
            TokenType::Pipe,
            TokenType::Caret,
            TokenType::Equal,
            TokenType::NotEqual,
            TokenType::Less,
            TokenType::Greater,
            TokenType::LessEqual,
            TokenType::GreaterEqual,
            TokenType::ShiftLeft,
            TokenType::ShiftRight,
            TokenType::LogicalAnd,
            TokenType::LogicalOr    => true,
            default                 => false,
        };
    }

    private function isAttributeIdentifier(string $value): bool
    {
        return $value === '__attribute__' || $value === '__attribute';
    }

    private function skipAttribute(): void
    {
        while (
            $this->current()->type === TokenType::Identifier
            && $this->isAttributeIdentifier($this->current()->value)
        ) {
            $this->advance(); // consume '__attribute__'
            $this->expect(TokenType::LeftParen); // first '('
            $depth = 1;
            while ($depth > 0 && !$this->check(TokenType::EOF)) {
                if ($this->check(TokenType::LeftParen)) {
                    $depth++;
                } elseif ($this->check(TokenType::RightParen)) {
                    $depth--;
                }
                $this->advance();
            }
        }
    }

    /**
     * Parse the contents of __typeof__(…).
     * Try to parse a type; if the token stream doesn't look like a type,
     * skip the contents and return a sensible default.
     */
    private function parseTypeofInner(): TypeNode
    {
        $cur = $this->current();

        // __typeof__(nullptr) → void*
        if ($cur->type === TokenType::Identifier && $cur->value === 'nullptr') {
            $this->advance();
            return new TypeNode(baseName: 'void', pointerDepth: 1);
        }

        // Try to detect if this looks like a type (type keyword, struct/union/enum,
        // known typedef, or qualifier like const/unsigned/etc.).
        if ($cur->type->isTypeKeyword()
            || $cur->type->isTypeQualifier()
            || $cur->type === TokenType::Struct
            || $cur->type === TokenType::Union
            || $cur->type === TokenType::Enum
            || $cur->type === TokenType::Void
            || isset($this->typeNames[$cur->value ?? ''])
            || ($cur->type === TokenType::Identifier && in_array($cur->value, ['__int128', '__int128_t', '__uint128_t', '__builtin_va_list'], true))
        ) {
            return $this->parseType();
        }

        // Not a recognizable type — skip balanced tokens until ')' and default to int.
        $depth = 0;
        while (!$this->check(TokenType::EOF)) {
            if ($this->check(TokenType::LeftParen)) {
                $depth++;
            } elseif ($this->check(TokenType::RightParen)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            }
            $this->advance();
        }
        return new TypeNode(baseName: 'int');
    }

    private function skipGccExtensions(): void
    {
        while ($this->current()->type === TokenType::Identifier) {
            $val = $this->current()->value;
            if ($val === '__extension__') {
                $this->advance();
            } elseif ($val === '__restrict' || $val === '__restrict__') {
                $this->advance();
            } elseif ($val === '__inline' || $val === '__inline__') {
                $this->advance();
            } elseif ($val === '__volatile__' || $val === '__volatile') {
                $this->advance();
            } elseif ($val === '__const__' || $val === '__const') {
                $this->advance();
            } elseif ($val === '__signed__' || $val === '__signed') {
                $this->advance();
            } elseif ($this->isAttributeIdentifier($val)) {
                $this->skipAttribute();
            } elseif ($val === '__asm__' || $val === '__asm') {
                $this->skipAsmLabel();
            } else {
                break;
            }
        }
    }

    /**
     * Consume an `__asm__("symbol")` or `__asm("symbol")` annotation.
     * The assembly name is ignored — we only need to skip it syntactically.
     */
    private function skipAsmLabel(): void
    {
        // Current token is '__asm__' or '__asm'
        if (
            $this->current()->type !== TokenType::Identifier
            || ($this->current()->value !== '__asm__' && $this->current()->value !== '__asm')
        ) {
            return;
        }
        $this->advance(); // consume '__asm__' / '__asm'

        if (!$this->check(TokenType::LeftParen)) {
            return;
        }

        // Consume balanced parentheses.
        $this->advance(); // consume '('
        $depth = 1;
        while ($depth > 0 && !$this->check(TokenType::EOF)) {
            if ($this->check(TokenType::LeftParen)) {
                $depth++;
            } elseif ($this->check(TokenType::RightParen)) {
                $depth--;
            }
            $this->advance();
        }
    }

    private function current(): Token
    {
        return $this->tokens[$this->pos] ?? $this->tokens[count($this->tokens) - 1];
    }

    /**
     * Lookahead by $offset positions relative to current.
     * Negative offsets look behind; returns null if out of bounds.
     */
    private function peek(int $offset = 1): ?Token
    {
        $idx = $this->pos + $offset;
        if ($idx < 0) {
            return null;
        }
        return $this->tokens[$idx] ?? $this->tokens[count($this->tokens) - 1];
    }

    private function advance(): Token
    {
        $tok = $this->current();
        if ($tok->type !== TokenType::EOF) {
            $this->pos++;
        }
        return $tok;
    }

    /** Advance and return the current token, or throw CompileError on mismatch. */
    private function expect(TokenType $type): Token
    {
        $tok = $this->current();
        if ($tok->type !== $type) {
            $this->error(
                "Expected '{$type->value}', got '{$tok->type->value}' ('{$tok->value}')",
                $tok,
            );
        }
        return $this->advance();
    }

    private function match(TokenType ...$types): bool
    {
        $cur = $this->current()->type;
        foreach ($types as $t) {
            if ($cur === $t) {
                return true;
            }
        }
        return false;
    }

    private function check(TokenType $type): bool
    {
        return $this->current()->type === $type;
    }

    /**
     * Consume the current token as a member name.
     * After `.` or `->`, C allows any identifier including words that the
     * lexer tokenises as keywords (e.g. X11's `visual_info->class`).
     */
    private function expectIdentifierOrKeyword(): string
    {
        $tok = $this->current();
        if ($tok->type === TokenType::Identifier) {
            $this->advance();
            return $tok->value;
        }
        // Accept any keyword token as a member name — in C, keywords are
        // valid struct/union member identifiers.
        if ($tok->type->isKeyword()) {
            $this->advance();
            return $tok->value;
        }
        $this->error("Expected identifier or keyword as member name");
    }

    /** @return never */
    private function error(string $message, ?Token $tok = null): never
    {
        $tok ??= $this->current();
        throw new CompileError($message, $tok->file, $tok->line, $tok->column);
    }

    /**
     * Error recovery: skip tokens until a synchronisation point
     * (semicolon or closing brace at the current nesting level).
     */
    private function synchronize(): void
    {
        while (!$this->check(TokenType::EOF)) {
            $type = $this->current()->type;
            if ($type === TokenType::Semicolon) {
                $this->advance();
                return;
            }
            if ($type === TokenType::RightBrace) {
                // Don't consume the brace — the caller's loop will see it.
                return;
            }
            $this->advance();
        }
    }
}
