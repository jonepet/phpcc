<?php

declare(strict_types=1);

namespace Cppc\Semantic;

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
use Cppc\AST\CharLiteralNode;
use Cppc\AST\ClassDeclaration;
use Cppc\AST\CommaExpr;
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
use Cppc\AST\NamespaceDeclaration;
use Cppc\AST\NewExpr;
use Cppc\AST\Node;
use Cppc\AST\NullptrLiteral;
use Cppc\AST\Parameter;
use Cppc\AST\ReturnStatement;
use Cppc\AST\ScopeResolutionExpr;
use Cppc\AST\SizeofExpr;
use Cppc\AST\StringLiteralNode;
use Cppc\AST\SwitchStatement;
use Cppc\AST\TernaryExpr;
use Cppc\AST\ThisExpr;
use Cppc\AST\TranslationUnit;
use Cppc\AST\TypedefDeclaration;
use Cppc\AST\TypeNode;
use Cppc\AST\UnaryExpr;
use Cppc\AST\VarDeclaration;
use Cppc\AST\WhileStatement;
use Cppc\AST\Cloner;
use Cppc\AST\TemplateDeclaration;
use Cppc\CompileError;

class Analyzer
{
    private SymbolTable $symbolTable;
    private TypeSystem $typeSystem;
    private Mangler $mangler;

    /** @var CompileError[] */
    private array $errors = [];

    private ?FunctionSymbol $currentFunction = null;
    private ?ClassSymbol $currentClass = null;

    /**
     * Map from spl_object_id(Node) to TypeNode — stores the resolved type of every expression.
     *
     * @var array<int, TypeNode>
     */
    private array $exprTypes = [];

    /**
     * Loop / switch nesting depth for break/continue validation.
     */
    private int $loopDepth   = 0;
    private int $switchDepth = 0;

    /** @var array<string, TemplateDeclaration> */
    private array $templates = [];

    /** @var array<string, bool> already-instantiated mangled names */
    private array $instantiated = [];

    /**
     * When true, all functions default to C linkage (no name mangling).
     * Set for .c source files; false for .cpp files.
     */
    private bool $cLinkageByDefault;

    public function __construct(bool $cLinkageByDefault = false)
    {
        $this->cLinkageByDefault = $cLinkageByDefault;
        $this->symbolTable = new SymbolTable();
        $this->typeSystem  = new TypeSystem();
        $this->mangler     = new Mangler();
    }

    public function analyze(TranslationUnit $ast): void
    {
        $this->registerTemplates($ast);
        $this->instantiateTemplates($ast);
        $this->collectDeclarations($ast);
        $this->typeCheck($ast);
    }

    /** @return CompileError[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getSymbolTable(): SymbolTable
    {
        return $this->symbolTable;
    }

    public function getExprType(Node $node): TypeNode
    {
        return $this->exprTypes[spl_object_id($node)] ?? TypeNode::int();
    }

    public function setExprType(Node $node, TypeNode $type): void
    {
        $this->exprTypes[spl_object_id($node)] = $type;
    }

    private function registerTemplates(TranslationUnit $ast): void
    {
        foreach ($ast->declarations as $decl) {
            if ($decl instanceof TemplateDeclaration && $decl->declaration instanceof ClassDeclaration) {
                $this->templates[$decl->declaration->name] = $decl;
            }
        }
    }

    private function instantiateTemplates(TranslationUnit $ast): void
    {
        $typeNodes = [];
        foreach ($ast->declarations as $decl) {
            if (!$decl instanceof TemplateDeclaration) {
                $this->collectTypeNodes($decl, $typeNodes);
            }
        }

        foreach ($typeNodes as $typeNode) {
            if ($typeNode->templateParam === null) {
                continue;
            }
            $baseName = $typeNode->baseName;
            if (!isset($this->templates[$baseName])) {
                continue;
            }

            $template = $this->templates[$baseName];
            $concreteType = $typeNode->templateParam;
            $mangledName = $baseName . '__' . $concreteType->baseName;

            if (!isset($this->instantiated[$mangledName])) {
                $paramName = $template->parameters[0]->name;
                $innerClass = $template->declaration;
                assert($innerClass instanceof ClassDeclaration);

                $instantiated = Cloner::instantiate($innerClass, $paramName, $concreteType, $mangledName);
                $ast->declarations[] = $instantiated;
                $this->instantiated[$mangledName] = true;
            }

            // Rewrite TypeNode in-place to plain class type
            $typeNode->baseName = $mangledName;
            $typeNode->className = $mangledName;
            $typeNode->templateParam = null;
        }
    }

    /**
     * Recursively collect all TypeNode references from an AST subtree.
     *
     * @param TypeNode[] &$result
     */
    private function collectTypeNodes(Node $node, array &$result): void
    {
        if ($node instanceof TypeNode) {
            $result[] = $node;
            if ($node->templateParam !== null) {
                $result[] = $node->templateParam;
            }
            if ($node->arraySize !== null) {
                $this->collectTypeNodes($node->arraySize, $result);
            }
            return;
        }

        // Declarations
        if ($node instanceof VarDeclaration) {
            $this->collectTypeNodes($node->type, $result);
            if ($node->initializer !== null) $this->collectTypeNodes($node->initializer, $result);
            if ($node->arraySize !== null) $this->collectTypeNodes($node->arraySize, $result);
            if ($node->arrayInit !== null) $this->collectTypeNodes($node->arrayInit, $result);
            return;
        }
        if ($node instanceof Parameter) {
            $this->collectTypeNodes($node->type, $result);
            if ($node->defaultValue !== null) $this->collectTypeNodes($node->defaultValue, $result);
            return;
        }
        if ($node instanceof FunctionDeclaration) {
            $this->collectTypeNodes($node->returnType, $result);
            foreach ($node->parameters as $p) $this->collectTypeNodes($p, $result);
            if ($node->body !== null) $this->collectTypeNodes($node->body, $result);
            if ($node->memberInitializers !== null) {
                foreach ($node->memberInitializers->initializers as $mi) {
                    foreach ($mi->arguments as $a) $this->collectTypeNodes($a, $result);
                }
            }
            return;
        }
        if ($node instanceof ClassDeclaration) {
            foreach ($node->members as $m) $this->collectTypeNodes($m, $result);
            return;
        }

        // Statements
        if ($node instanceof BlockStatement) {
            foreach ($node->statements as $s) $this->collectTypeNodes($s, $result);
            return;
        }
        if ($node instanceof ExpressionStatement) {
            $this->collectTypeNodes($node->expression, $result);
            return;
        }
        if ($node instanceof ReturnStatement) {
            if ($node->expression !== null) $this->collectTypeNodes($node->expression, $result);
            return;
        }
        if ($node instanceof IfStatement) {
            $this->collectTypeNodes($node->condition, $result);
            $this->collectTypeNodes($node->thenBranch, $result);
            if ($node->elseBranch !== null) $this->collectTypeNodes($node->elseBranch, $result);
            return;
        }
        if ($node instanceof WhileStatement) {
            $this->collectTypeNodes($node->condition, $result);
            $this->collectTypeNodes($node->body, $result);
            return;
        }
        if ($node instanceof DoWhileStatement) {
            $this->collectTypeNodes($node->body, $result);
            $this->collectTypeNodes($node->condition, $result);
            return;
        }
        if ($node instanceof ForStatement) {
            if ($node->init !== null) $this->collectTypeNodes($node->init, $result);
            if ($node->condition !== null) $this->collectTypeNodes($node->condition, $result);
            if ($node->update !== null) $this->collectTypeNodes($node->update, $result);
            $this->collectTypeNodes($node->body, $result);
            return;
        }
        if ($node instanceof SwitchStatement) {
            $this->collectTypeNodes($node->expression, $result);
            foreach ($node->cases as $c) {
                if ($c->value !== null) $this->collectTypeNodes($c->value, $result);
                foreach ($c->statements as $s) $this->collectTypeNodes($s, $result);
            }
            return;
        }
        if ($node instanceof LabelStatement) {
            $this->collectTypeNodes($node->statement, $result);
            return;
        }

        // Expressions with TypeNode children
        if ($node instanceof CastExpr) {
            $this->collectTypeNodes($node->targetType, $result);
            $this->collectTypeNodes($node->expression, $result);
            return;
        }
        if ($node instanceof NewExpr) {
            $this->collectTypeNodes($node->type, $result);
            foreach ($node->arguments as $a) $this->collectTypeNodes($a, $result);
            if ($node->arraySize !== null) $this->collectTypeNodes($node->arraySize, $result);
            return;
        }
        if ($node instanceof SizeofExpr) {
            if ($node->operand instanceof TypeNode) {
                $this->collectTypeNodes($node->operand, $result);
            } else {
                $this->collectTypeNodes($node->operand, $result);
            }
            return;
        }
        if ($node instanceof DeleteExpr) {
            $this->collectTypeNodes($node->operand, $result);
            return;
        }

        // Expressions with child nodes (no direct TypeNode)
        if ($node instanceof BinaryExpr) {
            $this->collectTypeNodes($node->left, $result);
            $this->collectTypeNodes($node->right, $result);
            return;
        }
        if ($node instanceof UnaryExpr) {
            $this->collectTypeNodes($node->operand, $result);
            return;
        }
        if ($node instanceof AssignExpr) {
            $this->collectTypeNodes($node->target, $result);
            $this->collectTypeNodes($node->value, $result);
            return;
        }
        if ($node instanceof CallExpr) {
            $this->collectTypeNodes($node->callee, $result);
            foreach ($node->arguments as $a) $this->collectTypeNodes($a, $result);
            return;
        }
        if ($node instanceof MemberAccessExpr) {
            $this->collectTypeNodes($node->object, $result);
            return;
        }
        if ($node instanceof ArrayAccessExpr) {
            $this->collectTypeNodes($node->array, $result);
            $this->collectTypeNodes($node->index, $result);
            return;
        }
        if ($node instanceof TernaryExpr) {
            $this->collectTypeNodes($node->condition, $result);
            $this->collectTypeNodes($node->trueExpr, $result);
            $this->collectTypeNodes($node->falseExpr, $result);
            return;
        }
        if ($node instanceof CommaExpr) {
            foreach ($node->expressions as $e) $this->collectTypeNodes($e, $result);
            return;
        }
        if ($node instanceof InitializerList) {
            foreach ($node->values as $v) $this->collectTypeNodes($v, $result);
            return;
        }
        if ($node instanceof DesignatedInit) {
            $this->collectTypeNodes($node->value, $result);
            return;
        }

        // Leaf nodes (literals, this, break, continue, goto, identifiers, scope resolution, access specifier) — no TypeNode children
    }

    private function collectDeclarations(TranslationUnit $ast): void
    {
        foreach ($ast->declarations as $decl) {
            $this->collectTopLevelDecl($decl);
        }
    }

    private function collectTopLevelDecl(Node $decl): void
    {
        if ($decl instanceof FunctionDeclaration) {
            $this->collectFunction($decl, null);
        } elseif ($decl instanceof ClassDeclaration) {
            $this->collectClass($decl);
        } elseif ($decl instanceof EnumDeclaration) {
            $this->collectEnum($decl);
        } elseif ($decl instanceof VarDeclaration) {
            $this->collectGlobal($decl);
        } elseif ($decl instanceof TypedefDeclaration) {
            $this->collectTypedef($decl);
        } elseif ($decl instanceof NamespaceDeclaration) {
            $this->collectNamespace($decl);
        }
        // TemplateDeclaration and others are noted but not deeply processed here.
    }

    private function collectFunction(FunctionDeclaration $decl, ?string $className): void
    {
        $sym = new FunctionSymbol(
            $decl->name,
            $decl->returnType,
            $decl->returnType,
        );
        $sym->isMethod   = $className !== null;
        $sym->className  = $className ?? '';
        $sym->isVirtual  = $decl->isVirtual;
        $sym->isStatic   = $decl->isStatic;
        $sym->isVariadic = $decl->isVariadic;
        $sym->kind       = SymbolKind::Function;

        foreach ($decl->parameters as $param) {
            $sym->params[] = $param->type;
        }

        // C linkage: use the unmangled name directly.
        // This applies when explicitly declared as extern "C", or when compiling a .c
        // file where all functions default to C linkage.
        if ($decl->linkage === 'C' || ($this->cLinkageByDefault && $decl->linkage === null && $className === null)) {
            $sym->mangledName = $decl->name;
        } elseif ($decl->isConstructor) {
            $sym->mangledName = $this->mangler->mangleConstructor($className ?? $decl->name, $sym->params);
        } elseif ($decl->isDestructor) {
            $sym->mangledName = $this->mangler->mangleDestructor($className ?? $decl->name);
        } else {
            $sym->mangledName = $this->mangler->mangle($decl->name, $className, $sym->params);
        }

        if ($className !== null) {
            $classSym = $this->symbolTable->lookupClass($className);
            if ($classSym !== null) {
                $classSym->methods[] = $sym;
            }
        }

        // Forward declarations may already be registered; skip silently.
        $existing = $this->symbolTable->lookupLocal($decl->name);
        if ($existing === null) {
            try {
                $this->symbolTable->define($sym);
            } catch (\RuntimeException) {
                // Forward declaration already registered; skip.
            }
        }
    }

    private function collectClass(ClassDeclaration $decl): void
    {
        if ($decl->isForwardDecl) {
            // Register a placeholder if not already present.
            if ($this->symbolTable->lookupLocal($decl->name) === null) {
                $placeholder = new ClassSymbol($decl->name);
                $placeholder->isStruct = $decl->isStruct;
                try {
                    $this->symbolTable->define($placeholder);
                } catch (\RuntimeException) {}
            }
            return;
        }

        $classSym = $this->symbolTable->lookupClass($decl->name);
        if ($classSym === null) {
            $classSym = new ClassSymbol($decl->name);
            try {
                $this->symbolTable->define($classSym);
            } catch (\RuntimeException) {}
        }

        $classSym->isStruct  = $decl->isStruct;
        $classSym->isUnion   = $decl->isUnion;
        $classSym->baseClass = $decl->baseClass;

        foreach ($decl->members as $member) {
            if ($member instanceof VarDeclaration) {
                $memberType = $member->type;
                if ($member->isArray) {
                    $arrSize = null;
                    if ($member->arraySize instanceof \Cppc\AST\IntLiteral) {
                        $arrSize = $member->arraySize->value;
                    }
                    $memberType = TypeNode::array($member->type, $arrSize);
                }
                $memberSym = new Symbol($member->name, $memberType, SymbolKind::Member);
                $memberSym->isStatic = $member->type->isStatic;
                $memberSym->bitWidth = $member->bitWidth;
                $classSym->members[] = $memberSym;
            } elseif ($member instanceof FunctionDeclaration) {
                $this->collectFunction($member, $decl->name);
            }
            // AccessSpecifier nodes are ignored here.
        }

        $this->layoutClass($classSym);

        // Register typedef alias (e.g. typedef struct _Foo { ... } Foo;)
        if ($decl->typedefAlias !== null) {
            $targetType = new TypeNode(baseName: $decl->name);
            $tdSym = new TypedefSymbol($decl->typedefAlias, $targetType);
            try {
                $this->symbolTable->define($tdSym);
            } catch (\RuntimeException) {}
        }
    }

    private function layoutClass(ClassSymbol $classSym): void
    {
        // Union layout: all members at offset 0, size = largest member.
        if ($classSym->isUnion) {
            $maxSize = 0;
            $maxAlign = 1;
            foreach ($classSym->members as $member) {
                if ($member->isStatic) {
                    continue;
                }
                $member->offset = 0;
                $size = $this->resolveTypeSize($member->type);
                $align = min(max($size, 1), 8);
                if ($size > $maxSize) {
                    $maxSize = $size;
                }
                if ($align > $maxAlign) {
                    $maxAlign = $align;
                }
            }
            $classSym->size = $maxSize > 0
                ? (int)(ceil($maxSize / $maxAlign) * $maxAlign)
                : 0;
            return;
        }

        $offset = 0;

        // If base class exists, inherit its size (which includes vptr if any).
        if ($classSym->baseClass !== null) {
            $baseSym = $this->symbolTable->lookupClass($classSym->baseClass);
            if ($baseSym !== null) {
                $offset = $baseSym->size;
            }
        }

        // Reserve 8 bytes for vptr if any virtual methods exist (own or inherited).
        if ($offset === 0) {
            $hasVirtual = false;
            foreach ($classSym->methods as $method) {
                if ($method->isVirtual) {
                    $hasVirtual = true;
                    break;
                }
            }
            if (!$hasVirtual && $classSym->baseClass !== null) {
                $baseSym = $this->symbolTable->lookupClass($classSym->baseClass);
                if ($baseSym !== null && count($baseSym->vtable) > 0) {
                    $hasVirtual = true;
                }
            }
            if ($hasVirtual) {
                $offset = 8; // vptr
            }
        }

        $bitFieldBitsUsed = 0;
        $bitFieldUnitSize = 0;
        $bitFieldUnitOffset = 0;

        foreach ($classSym->members as $member) {
            if ($member->isStatic) {
                continue;
            }

            if ($member->bitWidth !== null) {
                $unitSize = $this->resolveTypeSize($member->type);
                $unitBits = $unitSize * 8;

                if ($bitFieldUnitSize === 0 || $bitFieldBitsUsed + $member->bitWidth > $unitBits) {
                    if ($bitFieldUnitSize > 0) {
                        $offset = $bitFieldUnitOffset + $bitFieldUnitSize;
                    }
                    $align = min($unitSize, 8);
                    if ($align > 0) {
                        $offset = (int)(ceil($offset / $align) * $align);
                    }
                    $bitFieldUnitOffset = $offset;
                    $bitFieldUnitSize = $unitSize;
                    $bitFieldBitsUsed = 0;
                }

                $member->offset = $bitFieldUnitOffset;
                $member->bitOffset = $bitFieldBitsUsed;
                $bitFieldBitsUsed += $member->bitWidth;
                continue;
            }

            if ($bitFieldUnitSize > 0) {
                $offset = $bitFieldUnitOffset + $bitFieldUnitSize;
                $bitFieldUnitSize = 0;
                $bitFieldBitsUsed = 0;
            }

            $size = $this->resolveTypeSize($member->type);
            $align  = min($size, 8);
            if ($align > 0) {
                $offset = (int)(ceil($offset / $align) * $align);
            }
            $member->offset = $offset;
            $offset += $size;
        }

        if ($bitFieldUnitSize > 0) {
            $offset = $bitFieldUnitOffset + $bitFieldUnitSize;
        }

        $maxAlign = 1;
        foreach ($classSym->members as $member) {
            if ($member->isStatic || $member->bitWidth !== null) continue;
            $sz = $this->resolveTypeSize($member->type);
            $al = min(max($sz, 1), 8);
            if ($al > $maxAlign) $maxAlign = $al;
        }
        $classSym->size = $offset > 0 ? (int)(ceil($offset / $maxAlign) * $maxAlign) : 0;
    }

    private function resolveTypeSize(TypeNode $type): int
    {
        if ($type->pointerDepth > 0 || $type->isReference) {
            return 8;
        }
        if ($type->isArrayType()) {
            $elemSize = $this->resolveTypeSize($type->arrayElementType);
            return $type->arraySizeValue !== null ? $elemSize * $type->arraySizeValue : 0;
        }
        $builtIn = ['int', 'char', 'bool', 'long', 'short', 'float', 'double', 'void'];
        if (in_array($type->baseName, $builtIn, true)) {
            return $type->sizeInBytes();
        }
        if ($type->isEnum()) {
            return 4;
        }
        $name = $type->className ?? $type->baseName;
        $classSym = $this->symbolTable->lookupClass($name);
        if ($classSym !== null && $classSym->size > 0) {
            return $classSym->size;
        }
        $tdSym = $this->symbolTable->lookupTypedef($name);
        if ($tdSym !== null) {
            return $this->resolveTypeSize($tdSym->targetType);
        }
        return $type->sizeInBytes();
    }

    private function collectEnum(EnumDeclaration $decl): void
    {
        $enumType = new TypeNode(baseName: $decl->name);
        $enumSym  = new Symbol($decl->name, $enumType, SymbolKind::Enum);
        try {
            $this->symbolTable->define($enumSym);
        } catch (\RuntimeException) {}

        $counter = 0;
        foreach ($decl->entries as $entry) {
            if ($entry instanceof EnumEntry) {
                if ($entry->value instanceof IntLiteral) {
                    $counter = $entry->value->value;
                }
                $intType   = TypeNode::int();
                $valueSym  = new Symbol($entry->name, $intType, SymbolKind::EnumValue);
                $valueSym->enumValue = $counter;
                try {
                    $this->symbolTable->define($valueSym);
                } catch (\RuntimeException) {}
                $counter++;
            }
        }
    }

    private function collectGlobal(VarDeclaration $decl): void
    {
        $type = $decl->type;
        if ($decl->isArray && !$type->isArray) {
            $type = clone $type;
            $type->isArray = true;
        }
        $sym = new Symbol($decl->name, $type, SymbolKind::Variable);
        $sym->isConst  = $decl->type->isConst;
        $sym->isStatic = $decl->type->isStatic;
        try {
            $this->symbolTable->define($sym);
        } catch (\RuntimeException) {}
    }

    private function collectTypedef(TypedefDeclaration $decl): void
    {
        $sym = new TypedefSymbol($decl->alias, $decl->type);
        try {
            $this->symbolTable->define($sym);
        } catch (\RuntimeException) {}
    }

    private function collectNamespace(NamespaceDeclaration $decl): void
    {
        $nsSym = $this->symbolTable->lookupLocal($decl->name);
        if ($nsSym === null) {
            $nsType = new TypeNode(baseName: $decl->name);
            $nsSym  = new Symbol($decl->name, $nsType, SymbolKind::Namespace);
            try {
                $this->symbolTable->define($nsSym);
            } catch (\RuntimeException) {}
        }

        // Simplified: namespace body uses the same global scope rather than a child scope.
        foreach ($decl->declarations as $inner) {
            $this->collectTopLevelDecl($inner);
        }
    }

    private function typeCheck(TranslationUnit $ast): void
    {
        foreach ($ast->declarations as $decl) {
            $this->typeCheckTopLevel($decl);
        }
    }

    private function typeCheckTopLevel(Node $decl): void
    {
        if ($decl instanceof FunctionDeclaration) {
            $this->analyzeFunction($decl);
        } elseif ($decl instanceof ClassDeclaration) {
            $this->analyzeClass($decl);
        } elseif ($decl instanceof EnumDeclaration) {
            // Nothing further to check for enums after pass 1.
        } elseif ($decl instanceof VarDeclaration) {
            if ($decl->initializer !== null) {
                $initType = $this->analyzeExpression($decl->initializer);
                if (!$this->typeSystem->isAssignableTo($initType, $decl->type)) {
                    $this->error(
                        "Cannot initialize '{$decl->name}' of type '{$decl->type}' "
                        . "with value of type '{$initType}'",
                        $decl
                    );
                }
            }
        } elseif ($decl instanceof TypedefDeclaration) {
            // Nothing to check.
        } elseif ($decl instanceof NamespaceDeclaration) {
            foreach ($decl->declarations as $inner) {
                $this->typeCheckTopLevel($inner);
            }
        }
    }

    public function analyzeFunction(FunctionDeclaration $node): void
    {
        $sym = $this->symbolTable->lookupFunction($node->name);
        if ($sym === null) {
            return; // Forward declaration without body; nothing to do.
        }

        if ($node->body === null) {
            return; // Forward declaration.
        }

        $prevFunction = $this->currentFunction;
        $this->currentFunction = $sym;

        $this->symbolTable = $this->symbolTable->enterScope();

        foreach ($node->parameters as $param) {
            $paramSym = new Symbol(
                $param->name !== '' ? $param->name : '__anon',
                $param->type,
                SymbolKind::Parameter
            );
            try {
                $this->symbolTable->define($paramSym);
            } catch (\RuntimeException) {}
        }

        $this->analyzeBlock($node->body);

        $this->symbolTable    = $this->symbolTable->exitScope();
        $this->currentFunction = $prevFunction;
    }

    public function analyzeClass(ClassDeclaration $node): void
    {
        if ($node->isForwardDecl) {
            return;
        }

        $classSym = $this->symbolTable->lookupClass($node->name);
        if ($classSym === null) {
            return;
        }

        if ($node->baseClass !== null) {
            $baseSym = $this->symbolTable->lookupClass($node->baseClass);
            if ($baseSym === null) {
                $this->error(
                    "Base class '{$node->baseClass}' of '{$node->name}' is not defined",
                    $node
                );
            } else {
                $classSym->vtable = $baseSym->vtable;
            }
        }

        $this->buildVtable($classSym);

        $prevClass = $this->currentClass;
        $this->currentClass = $classSym;

        foreach ($node->members as $member) {
            if ($member instanceof FunctionDeclaration && $member->body !== null) {
                $this->analyzeFunction($member);
            } elseif ($member instanceof VarDeclaration && $member->initializer !== null) {
                $initType = $this->analyzeExpression($member->initializer);
                if (!$this->typeSystem->isAssignableTo($initType, $member->type)) {
                    $this->error(
                        "Member initializer type mismatch for '{$member->name}'",
                        $member
                    );
                }
            }
        }

        $this->currentClass = $prevClass;
    }

    private function buildVtable(ClassSymbol $classSym): void
    {
        // Methods overriding an inherited slot replace it; new virtual methods append.
        $nextSlot = count($classSym->vtable);
        foreach ($classSym->methods as $method) {
            // Check if this method overrides a base class vtable entry.
            $overridden = false;
            foreach ($classSym->vtable as $slot => $existing) {
                if ($existing->name === $method->name) {
                    $method->isVirtual = true;
                    $classSym->vtable[$slot] = $method;
                    $overridden = true;
                    break;
                }
            }
            if (!$overridden && $method->isVirtual) {
                $classSym->vtable[$nextSlot++] = $method;
            }
        }
    }

    public function analyzeStatement(Node $stmt): void
    {
        if ($stmt instanceof BlockStatement) {
            // Comma-separated declarations produce a synthetic BlockStatement
            // containing only VarDeclarations.  These must be defined in the
            // CURRENT scope, not a nested one, so the variables remain visible
            // to subsequent statements in the enclosing block.
            $allVarDecls = true;
            foreach ($stmt->statements as $s) {
                if (!$s instanceof VarDeclaration) {
                    $allVarDecls = false;
                    break;
                }
            }
            if ($allVarDecls) {
                foreach ($stmt->statements as $s) {
                    $this->analyzeStatement($s);
                }
            } else {
                $this->analyzeBlock($stmt);
            }
        } elseif ($stmt instanceof ExpressionStatement) {
            $this->analyzeExpression($stmt->expression);
        } elseif ($stmt instanceof ReturnStatement) {
            $this->analyzeReturn($stmt);
        } elseif ($stmt instanceof IfStatement) {
            $this->analyzeIf($stmt);
        } elseif ($stmt instanceof WhileStatement) {
            $this->analyzeWhile($stmt);
        } elseif ($stmt instanceof DoWhileStatement) {
            $this->analyzeDoWhile($stmt);
        } elseif ($stmt instanceof ForStatement) {
            $this->analyzeFor($stmt);
        } elseif ($stmt instanceof SwitchStatement) {
            $this->analyzeSwitch($stmt);
        } elseif ($stmt instanceof BreakStatement) {
            if ($this->loopDepth === 0 && $this->switchDepth === 0) {
                $this->error('break statement outside of loop or switch', $stmt);
            }
        } elseif ($stmt instanceof ContinueStatement) {
            if ($this->loopDepth === 0) {
                $this->error('continue statement outside of loop', $stmt);
            }
        } elseif ($stmt instanceof VarDeclaration) {
            $this->analyzeLocalVar($stmt);
        } elseif ($stmt instanceof LabelStatement) {
            $this->analyzeStatement($stmt->statement);
        } elseif ($stmt instanceof GotoStatement) {
            // Goto validation would require a two-pass over labels; record but skip.
        }
        // Other statement types are silently accepted.
    }

    private function analyzeBlock(BlockStatement $block): void
    {
        $this->symbolTable = $this->symbolTable->enterScope();
        foreach ($block->statements as $stmt) {
            $this->analyzeStatement($stmt);
        }
        $this->symbolTable = $this->symbolTable->exitScope();
    }

    private function analyzeReturn(ReturnStatement $stmt): void
    {
        if ($this->currentFunction === null) {
            $this->error('return statement outside of function', $stmt);
            return;
        }

        $expected = $this->currentFunction->returnType;

        if ($stmt->expression === null) {
            if (!$expected->isVoid()) {
                $this->error(
                    "Function must return a value of type '{$expected}'",
                    $stmt
                );
            }
            return;
        }

        if ($expected->isVoid()) {
            $this->error('void function should not return a value', $stmt);
            return;
        }

        $actualType = $this->analyzeExpression($stmt->expression);
        if (!$this->typeSystem->isAssignableTo($actualType, $expected)) {
            $this->error(
                "Cannot convert return type '{$actualType}' to '{$expected}'",
                $stmt
            );
        }
    }

    private function analyzeIf(IfStatement $stmt): void
    {
        $condType = $this->analyzeExpression($stmt->condition);
        // Condition must be convertible to bool.
        if (!$this->typeSystem->isCompatible($condType, TypeNode::bool())
            && !$condType->isNumeric()
            && $condType->pointerDepth === 0
        ) {
            $this->error(
                "If condition of type '{$condType}' is not convertible to bool",
                $stmt
            );
        }
        $this->analyzeStatement($stmt->thenBranch);
        if ($stmt->elseBranch !== null) {
            $this->analyzeStatement($stmt->elseBranch);
        }
    }

    private function analyzeWhile(WhileStatement $stmt): void
    {
        $this->analyzeExpression($stmt->condition);
        $this->loopDepth++;
        $this->analyzeStatement($stmt->body);
        $this->loopDepth--;
    }

    private function analyzeDoWhile(DoWhileStatement $stmt): void
    {
        $this->loopDepth++;
        $this->analyzeStatement($stmt->body);
        $this->loopDepth--;
        $this->analyzeExpression($stmt->condition);
    }

    private function analyzeFor(ForStatement $stmt): void
    {
        $this->symbolTable = $this->symbolTable->enterScope();

        if ($stmt->init !== null) {
            if ($stmt->init instanceof VarDeclaration) {
                $this->analyzeLocalVar($stmt->init);
            } elseif ($stmt->init instanceof BlockStatement) {
                // Comma-declared vars in for-init: int x = 0, y = 100
                // Analyze in the for-loop's scope (not a nested one).
                foreach ($stmt->init->statements as $s) {
                    $this->analyzeStatement($s);
                }
            } elseif ($stmt->init instanceof ExpressionStatement) {
                $this->analyzeExpression($stmt->init->expression);
            } else {
                $this->analyzeExpression($stmt->init);
            }
        }
        if ($stmt->condition !== null) {
            $this->analyzeExpression($stmt->condition);
        }
        if ($stmt->update !== null) {
            $this->analyzeExpression($stmt->update);
        }

        $this->loopDepth++;
        $this->analyzeStatement($stmt->body);
        $this->loopDepth--;

        $this->symbolTable = $this->symbolTable->exitScope();
    }

    private function analyzeSwitch(SwitchStatement $stmt): void
    {
        $exprType = $this->analyzeExpression($stmt->expression);
        if (!$exprType->isInteger()) {
            $this->error(
                "Switch expression must be an integral type, got '{$exprType}'",
                $stmt
            );
        }

        $this->switchDepth++;
        foreach ($stmt->cases as $case) {
            if ($case instanceof CaseClause) {
                if ($case->value !== null) {
                    $this->analyzeExpression($case->value);
                }
                foreach ($case->statements as $caseStmt) {
                    $this->analyzeStatement($caseStmt);
                }
            }
        }
        $this->switchDepth--;
    }

    private function analyzeLocalVar(VarDeclaration $decl): void
    {
        $type = $decl->type;
        // Propagate array-ness from the declaration to the type so that
        // analyzeArrayAccess can tell this is an array.
        if ($decl->isArray && !$type->isArray) {
            $type = clone $type;
            $type->isArray = true;
        }
        $sym = new Symbol($decl->name, $type, SymbolKind::Variable);
        $sym->isConst  = $decl->type->isConst;
        $sym->isStatic = $decl->type->isStatic;
        try {
            $this->symbolTable->define($sym);
        } catch (\RuntimeException) {
            $this->error("Redefinition of variable '{$decl->name}'", $decl);
        }

        if ($decl->initializer !== null) {
            $initType = $this->analyzeExpression($decl->initializer);
            if (!$this->typeSystem->isAssignableTo($initType, $decl->type)) {
                $this->error(
                    "Cannot initialize '{$decl->name}' of type '{$decl->type}' "
                    . "with value of type '{$initType}'",
                    $decl
                );
            }
        }

        if ($decl->arrayInit !== null) {
            foreach ($decl->arrayInit->values as $val) {
                $this->analyzeExpression($val);
            }
        }
    }

    public function analyzeExpression(Node $expr): TypeNode
    {
        $type = $this->analyzeExpressionInner($expr);
        $this->setExprType($expr, $type);
        return $type;
    }

    private function analyzeExpressionInner(Node $expr): TypeNode
    {
        if ($expr instanceof IntLiteral) {
            return TypeNode::int();
        }

        if ($expr instanceof FloatLiteral) {
            return TypeNode::double();
        }

        if ($expr instanceof CharLiteralNode) {
            return TypeNode::char();
        }

        if ($expr instanceof StringLiteralNode) {
            return TypeNode::charPtr();
        }

        if ($expr instanceof BoolLiteral) {
            return TypeNode::bool();
        }

        if ($expr instanceof NullptrLiteral) {
            return new TypeNode(baseName: 'nullptr_t');
        }

        if ($expr instanceof IdentifierExpr) {
            return $this->analyzeIdentifier($expr);
        }

        if ($expr instanceof ScopeResolutionExpr) {
            return $this->analyzeScopeResolution($expr);
        }

        if ($expr instanceof BinaryExpr) {
            return $this->analyzeBinary($expr);
        }

        if ($expr instanceof UnaryExpr) {
            return $this->analyzeUnary($expr);
        }

        if ($expr instanceof AssignExpr) {
            return $this->analyzeAssign($expr);
        }

        if ($expr instanceof CallExpr) {
            return $this->analyzeCall($expr);
        }

        if ($expr instanceof MemberAccessExpr) {
            return $this->analyzeMemberAccess($expr);
        }

        if ($expr instanceof ArrayAccessExpr) {
            return $this->analyzeArrayAccess($expr);
        }

        if ($expr instanceof CastExpr) {
            return $this->analyzeCast($expr);
        }

        if ($expr instanceof TernaryExpr) {
            return $this->analyzeTernary($expr);
        }

        if ($expr instanceof SizeofExpr) {
            return TypeNode::long(); // size_t approximated as long
        }

        if ($expr instanceof NewExpr) {
            return $this->analyzeNew($expr);
        }

        if ($expr instanceof DeleteExpr) {
            $this->analyzeExpression($expr->operand);
            return TypeNode::void();
        }

        if ($expr instanceof CommaExpr) {
            $last = TypeNode::void();
            foreach ($expr->expressions as $subExpr) {
                $last = $this->analyzeExpression($subExpr);
            }
            return $last;
        }

        if ($expr instanceof ThisExpr) {
            return $this->analyzeThis($expr);
        }

        if ($expr instanceof InitializerList) {
            foreach ($expr->values as $val) {
                $this->analyzeExpression($val);
            }
            return TypeNode::void(); // type depends on context
        }

        if ($expr instanceof DesignatedInit) {
            return $this->analyzeExpression($expr->value);
        }

        // Fallback: return int to allow recovery.
        return TypeNode::int();
    }

    private function analyzeIdentifier(IdentifierExpr $expr): TypeNode
    {
        $sym = $this->symbolTable->lookup($expr->name);
        if ($sym !== null) {
            return $sym->type;
        }

        // Implicit this->member access inside a method.
        if ($this->currentClass !== null) {
            $member = $this->currentClass->findMember($expr->name);
            if ($member !== null) {
                return $member->type;
            }
            $method = $this->currentClass->findMethod($expr->name);
            if ($method !== null) {
                return $method->returnType;
            }
        }

        $this->error("Undeclared identifier '{$expr->name}'", $expr);
        return TypeNode::int();
    }

    private function analyzeScopeResolution(ScopeResolutionExpr $expr): TypeNode
    {
        // Enum::Value — look up the enum value symbol.
        if ($expr->scope !== null) {
            $sym = $this->symbolTable->lookup($expr->name);
            if ($sym !== null) {
                return $sym->type;
            }
            // Try to look up as a class member.
            $classSym = $this->symbolTable->lookupClass($expr->scope);
            if ($classSym !== null) {
                $member = $classSym->findMember($expr->name)
                    ?? $classSym->findMethod($expr->name);
                if ($member !== null) {
                    return $member->type;
                }
            }
            $this->error("Unknown scope resolution '{$expr->scope}::{$expr->name}'", $expr);
        }
        return TypeNode::int();
    }

    private function analyzeBinary(BinaryExpr $expr): TypeNode
    {
        $leftType  = $this->analyzeExpression($expr->left);
        $rightType = $this->analyzeExpression($expr->right);

        $result = $this->typeSystem->getResultType($expr->operator, $leftType, $rightType);

        // Validate operand compatibility for arithmetic/comparison operators.
        $arithOps = ['+', '-', '*', '/', '%', '&', '|', '^', '<<', '>>'];
        $cmpOps   = ['==', '!=', '<', '>', '<=', '>='];

        if (in_array($expr->operator, $arithOps, true)) {
            if (!$leftType->isNumeric() && $leftType->pointerDepth === 0) {
                $this->error(
                    "Left operand of '{$expr->operator}' is not numeric (got '{$leftType}')",
                    $expr
                );
            }
            if (!$rightType->isNumeric() && $rightType->pointerDepth === 0) {
                $this->error(
                    "Right operand of '{$expr->operator}' is not numeric (got '{$rightType}')",
                    $expr
                );
            }
        } elseif (in_array($expr->operator, $cmpOps, true)) {
            if (!$this->typeSystem->isCompatible($leftType, $rightType)
                && !$this->typeSystem->isCompatible($rightType, $leftType)
            ) {
                $this->error(
                    "Incompatible types in comparison: '{$leftType}' and '{$rightType}'",
                    $expr
                );
            }
        }

        return $result;
    }

    private function analyzeUnary(UnaryExpr $expr): TypeNode
    {
        $operandType = $this->analyzeExpression($expr->operand);

        if ($expr->operator === '&') {
            // Address-of: T → T*
            $result = clone $operandType;
            $result->pointerDepth++;
            $result->isReference = false;
            return $result;
        }

        if ($expr->operator === '*') {
            // Dereference: T* → T
            if ($operandType->pointerDepth === 0) {
                $this->error(
                    "Cannot dereference non-pointer type '{$operandType}'",
                    $expr
                );
                return $operandType;
            }
            $result = clone $operandType;
            $result->pointerDepth--;
            return $result;
        }

        return $this->typeSystem->getResultType($expr->operator, $operandType);
    }

    private function analyzeAssign(AssignExpr $expr): TypeNode
    {
        $targetType = $this->analyzeExpression($expr->target);
        $valueType  = $this->analyzeExpression($expr->value);

        if ($targetType->isConst) {
            $this->error('Cannot assign to a const-qualified variable', $expr);
        }

        if (!$this->typeSystem->isAssignableTo($valueType, $targetType)) {
            $this->error(
                "Cannot assign value of type '{$valueType}' to '{$targetType}'",
                $expr
            );
        }

        return $targetType;
    }

    private function analyzeCall(CallExpr $expr): TypeNode
    {
        $argTypes = [];
        foreach ($expr->arguments as $arg) {
            $argTypes[] = $this->analyzeExpression($arg);
        }

        if ($expr->callee instanceof IdentifierExpr) {
            return $this->resolveFreeFunctionCall($expr->callee->name, $argTypes, $expr);
        }

        if ($expr->callee instanceof MemberAccessExpr) {
            return $this->resolveMemberCall($expr->callee, $argTypes, $expr);
        }

        if ($expr->callee instanceof ScopeResolutionExpr) {
            $name = $expr->callee->name;
            return $this->resolveFreeFunctionCall($name, $argTypes, $expr);
        }

        // Pointer-to-function or other complex callee — best effort.
        $calleeType = $this->analyzeExpression($expr->callee);
        return $calleeType;
    }

    /**
     * @param TypeNode[] $argTypes
     */
    private function resolveFreeFunctionCall(
        string $name,
        array $argTypes,
        Node $errorNode
    ): TypeNode {
        // GCC __builtin_* functions are compiler intrinsics. Don't error on them;
        // the IR generator will lower them to the appropriate standard-library calls.
        if (str_starts_with($name, '__builtin_')) {
            // __builtin_constant_p always evaluates to 0 (int).
            // __builtin_expect returns its first argument (int/long).
            // All others delegate to the stripped standard function — return int
            // as a conservative return type (correct for most builtins).
            return TypeNode::int();
        }

        $sym = $this->symbolTable->lookup($name);
        if ($sym === null) {
            $this->error("Call to undeclared function '{$name}'", $errorNode);
            return TypeNode::int();
        }
        if (!($sym instanceof FunctionSymbol)) {
            // Check if it's a function pointer variable
            if ($sym->type->isFunctionPointer()) {
                return $sym->type->getFuncPtrReturnType() ?? TypeNode::int();
            }
            $this->error("'{$name}' is not a function", $errorNode);
            return $sym->type;
        }

        $this->checkCallArguments($sym, $argTypes, $errorNode);
        return $sym->returnType;
    }

    /**
     * @param TypeNode[] $argTypes
     */
    private function resolveMemberCall(
        MemberAccessExpr $callee,
        array $argTypes,
        Node $errorNode
    ): TypeNode {
        $objectType = $this->analyzeExpression($callee->object);

        if ($callee->isArrow) {
            if ($objectType->pointerDepth === 0) {
                $this->error(
                    "Arrow operator '->' used on non-pointer type '{$objectType}'",
                    $callee
                );
                return TypeNode::int();
            }
            $derefType = clone $objectType;
            $derefType->pointerDepth--;
            $objectType = $derefType;
        }

        $className = $objectType->className ?? $objectType->baseName;
        $classSym  = $this->symbolTable->lookupClass($className);
        if ($classSym === null) {
            $this->error(
                "Type '{$objectType}' has no members (class not found)",
                $errorNode
            );
            return TypeNode::int();
        }

        $method = $this->findMethod($classSym, $callee->member);
        if ($method === null) {
            $this->error(
                "Class '{$className}' has no method '{$callee->member}'",
                $errorNode
            );
            return TypeNode::int();
        }

        $this->checkCallArguments($method, $argTypes, $errorNode);
        return $method->returnType;
    }

    /**
     * @param TypeNode[] $argTypes
     */
    private function checkCallArguments(
        FunctionSymbol $sym,
        array $argTypes,
        Node $errorNode
    ): void {
        if ($sym->isVariadic) {
            // Variadic: require at least the fixed params, allow any extras.
            if (count($argTypes) < count($sym->params)) {
                $this->error(
                    "Function '{$sym->name}' requires at least " . count($sym->params)
                    . ' argument(s), got ' . count($argTypes),
                    $errorNode
                );
                return;
            }
            // Only type-check the fixed parameters.
            foreach ($sym->params as $i => $paramType) {
                if (!isset($argTypes[$i])) {
                    break;
                }
                if (!$this->typeSystem->isCompatible($argTypes[$i], $paramType)) {
                    $this->error(
                        "Argument " . ($i + 1) . " of '{$sym->name}': cannot convert "
                        . "'{$argTypes[$i]}' to '{$paramType}'",
                        $errorNode
                    );
                }
            }
            return;
        }

        if (count($argTypes) !== count($sym->params)) {
            $this->error(
                "Function '{$sym->name}' expects " . count($sym->params)
                . ' argument(s), got ' . count($argTypes),
                $errorNode
            );
            return;
        }

        foreach ($sym->params as $i => $paramType) {
            if (!isset($argTypes[$i])) {
                break;
            }
            if (!$this->typeSystem->isCompatible($argTypes[$i], $paramType)) {
                $this->error(
                    "Argument " . ($i + 1) . " of '{$sym->name}': cannot convert "
                    . "'{$argTypes[$i]}' to '{$paramType}'",
                    $errorNode
                );
            }
        }
    }

    private function analyzeMemberAccess(MemberAccessExpr $expr): TypeNode
    {
        $objectType = $this->analyzeExpression($expr->object);

        if ($expr->isArrow) {
            if ($objectType->pointerDepth === 0) {
                $this->error(
                    "Arrow '->' used on non-pointer type '{$objectType}'",
                    $expr
                );
                return TypeNode::int();
            }
            $deref = clone $objectType;
            $deref->pointerDepth--;
            $objectType = $deref;
        }

        $className = $objectType->className ?? $objectType->baseName;
        $classSym  = $this->symbolTable->lookupClass($className);
        if ($classSym === null) {
            $tdSym = $this->symbolTable->lookupTypedef($className);
            if ($tdSym !== null) {
                $resolved = $tdSym->targetType->className ?? $tdSym->targetType->baseName;
                $classSym = $this->symbolTable->lookupClass($resolved);
            }
        }
        if ($classSym === null) {
            $this->error(
                "Type '{$objectType}' has no members (class '{$className}' not found)",
                $expr
            );
            return TypeNode::int();
        }

        $member = $classSym->findMember($expr->member);
        if ($member !== null) {
            return $member->type;
        }

        $method = $this->findMethod($classSym, $expr->member);
        if ($method !== null) {
            return $method->returnType;
        }

        $this->error(
            "Class '{$className}' has no member '{$expr->member}'",
            $expr
        );
        return TypeNode::int();
    }

    private function findMethod(ClassSymbol $classSym, string $name): ?FunctionSymbol
    {
        $found = $classSym->findMethod($name);
        if ($found !== null) {
            return $found;
        }

        if ($classSym->baseClass !== null) {
            $baseSym = $this->symbolTable->lookupClass($classSym->baseClass);
            if ($baseSym !== null) {
                return $this->findMethod($baseSym, $name);
            }
        }

        return null;
    }

    private function analyzeArrayAccess(ArrayAccessExpr $expr): TypeNode
    {
        $arrayType = $this->analyzeExpression($expr->array);
        $indexType = $this->analyzeExpression($expr->index);

        if (!$indexType->isInteger()) {
            $this->error(
                "Array index must be an integer type, got '{$indexType}'",
                $expr
            );
        }

        if ($arrayType->pointerDepth > 0) {
            $elem = clone $arrayType;
            $elem->pointerDepth--;
            return $elem;
        }

        if ($arrayType->isArray) {
            $elem = clone $arrayType;
            $elem->isArray  = false;
            $elem->arraySize = null;
            return $elem;
        }

        $this->error(
            "Subscript applied to non-array/non-pointer type '{$arrayType}'",
            $expr
        );
        return TypeNode::int();
    }

    private function analyzeCast(CastExpr $expr): TypeNode
    {
        $fromType = $this->analyzeExpression($expr->expression);
        $toType   = $expr->targetType;

        $valid = match ($expr->castKind) {
            'static_cast'      => $this->typeSystem->canExplicitCast($fromType, $toType),
            'reinterpret_cast' => true, // Allowed for any pointer/integral combination.
            'const_cast'       => true, // Allowed for adding/removing const.
            'dynamic_cast'     => $fromType->pointerDepth > 0 || $fromType->isReference,
            'c_style'          => $this->typeSystem->canExplicitCast($fromType, $toType),
            default            => true,
        };

        if (!$valid) {
            $this->error(
                "Invalid {$expr->castKind}: cannot convert '{$fromType}' to '{$toType}'",
                $expr
            );
        }

        return $toType;
    }

    private function analyzeTernary(TernaryExpr $expr): TypeNode
    {
        $this->analyzeExpression($expr->condition);
        $trueType  = $this->analyzeExpression($expr->trueExpr);
        $falseType = $this->analyzeExpression($expr->falseExpr);

        if ($trueType->equals($falseType)) {
            return $trueType;
        }

        if ($trueType->isNumeric() && $falseType->isNumeric()) {
            return $this->typeSystem->getCommonType($trueType, $falseType);
        }

        if ($this->typeSystem->isCompatible($falseType, $trueType)) {
            return $trueType;
        }
        if ($this->typeSystem->isCompatible($trueType, $falseType)) {
            return $falseType;
        }

        $this->error(
            "Ternary branches have incompatible types: '{$trueType}' and '{$falseType}'",
            $expr
        );
        return $trueType;
    }

    private function analyzeNew(NewExpr $expr): TypeNode
    {
        foreach ($expr->arguments as $arg) {
            $this->analyzeExpression($arg);
        }

        $resultType = clone $expr->type;
        if ($expr->isArray) {
            if ($expr->arraySize !== null) {
                $sizeType = $this->analyzeExpression($expr->arraySize);
                if (!$sizeType->isInteger()) {
                    $this->error('Array size in new[] must be an integer type', $expr);
                }
            }
        }
        $resultType->pointerDepth++;
        $resultType->isReference = false;
        return $resultType;
    }

    private function analyzeThis(ThisExpr $expr): TypeNode
    {
        if ($this->currentClass === null) {
            $this->error("'this' used outside of a member function", $expr);
            return new TypeNode(baseName: 'void', pointerDepth: 1);
        }
        $type = clone $this->currentClass->type;
        $type->pointerDepth = 1;
        $type->isReference  = false;
        return $type;
    }

    private function error(string $message, Node $node): void
    {
        // Limit accumulated errors to prevent OOM on large C files with
        // many type mismatches our incomplete type system can't resolve.
        if (count($this->errors) >= 200) {
            return;
        }
        $this->errors[] = new CompileError(
            $message,
            $node->file,
            $node->line,
            $node->column,
        );
    }
}
