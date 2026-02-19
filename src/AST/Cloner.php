<?php

declare(strict_types=1);

namespace Cppc\AST;

class Cloner
{
    public static function instantiate(
        ClassDeclaration $template,
        string $paramName,
        TypeNode $concreteType,
        string $newClassName,
    ): ClassDeclaration {
        $cloner = new self($paramName, $concreteType, $newClassName);
        return $cloner->cloneClass($template);
    }

    private function __construct(
        private readonly string $paramName,
        private readonly TypeNode $concreteType,
        private readonly string $newClassName,
    ) {}

    private function cloneClass(ClassDeclaration $node): ClassDeclaration
    {
        $members = [];
        foreach ($node->members as $m) {
            $members[] = $this->cloneNode($m);
        }
        return new ClassDeclaration(
            name: $this->newClassName,
            members: $members,
            isStruct: $node->isStruct,
            baseClass: $node->baseClass,
            baseAccess: $node->baseAccess,
            isForwardDecl: $node->isForwardDecl,
        );
    }

    private function cloneType(TypeNode $t): TypeNode
    {
        if ($t->baseName === $this->paramName && $t->className === null) {
            $result = new TypeNode(
                baseName: $this->concreteType->baseName,
                isConst: $t->isConst || $this->concreteType->isConst,
                isUnsigned: $this->concreteType->isUnsigned,
                isSigned: $this->concreteType->isSigned,
                isLong: $this->concreteType->isLong,
                isShort: $this->concreteType->isShort,
                isStatic: $t->isStatic,
                isExtern: $t->isExtern,
                isInline: $t->isInline,
                isVirtual: $t->isVirtual,
                pointerDepth: $this->concreteType->pointerDepth + $t->pointerDepth,
                isReference: $t->isReference,
                templateParam: null,
                className: $this->concreteType->className,
                namespacePath: $this->concreteType->namespacePath,
                isArray: $t->isArray,
                arraySize: $t->arraySize !== null ? $this->cloneNode($t->arraySize) : null,
            );
            $this->copyCompoundTypeProps($t, $result);
            return $result;
        }

        $result = new TypeNode(
            baseName: $t->baseName,
            isConst: $t->isConst,
            isUnsigned: $t->isUnsigned,
            isSigned: $t->isSigned,
            isLong: $t->isLong,
            isShort: $t->isShort,
            isStatic: $t->isStatic,
            isExtern: $t->isExtern,
            isInline: $t->isInline,
            isVirtual: $t->isVirtual,
            pointerDepth: $t->pointerDepth,
            isReference: $t->isReference,
            templateParam: $t->templateParam !== null ? $this->cloneType($t->templateParam) : null,
            className: $t->className,
            namespacePath: $t->namespacePath,
            isArray: $t->isArray,
            arraySize: $t->arraySize !== null ? $this->cloneNode($t->arraySize) : null,
        );
        $this->copyCompoundTypeProps($t, $result);
        return $result;
    }

    /**
     * Copy array/function-pointer compound type properties from source to destination.
     */
    private function copyCompoundTypeProps(TypeNode $src, TypeNode $dst): void
    {
        if ($src->arrayElementType !== null) {
            $dst->arrayElementType = $this->cloneType($src->arrayElementType);
            $dst->arraySizeValue = $src->arraySizeValue;
        }
        if ($src->funcPtrReturnType !== null) {
            $dst->funcPtrReturnType = $this->cloneType($src->funcPtrReturnType);
            $dst->funcPtrParamTypes = array_map(
                fn(TypeNode $p) => $this->cloneType($p),
                $src->funcPtrParamTypes ?? [],
            );
        }
    }

    private function cloneNode(Node $node): Node
    {
        return match (true) {
            // Declarations
            $node instanceof OperatorDeclaration => $this->cloneOperatorDecl($node),
            $node instanceof FunctionDeclaration => $this->cloneFunctionDecl($node),
            $node instanceof VarDeclaration => $this->cloneVarDecl($node),
            $node instanceof Parameter => $this->cloneParameter($node),
            $node instanceof ClassDeclaration => $this->cloneClass($node),
            $node instanceof AccessSpecifier => new AccessSpecifier($node->access),
            $node instanceof MemberInitializerList => $this->cloneMemberInitList($node),
            $node instanceof MemberInitializer => $this->cloneMemberInit($node),

            // Statements
            $node instanceof BlockStatement => $this->cloneBlock($node),
            $node instanceof ExpressionStatement => new ExpressionStatement($this->cloneNode($node->expression)),
            $node instanceof ReturnStatement => new ReturnStatement(
                $node->expression !== null ? $this->cloneNode($node->expression) : null,
            ),
            $node instanceof IfStatement => new IfStatement(
                $this->cloneNode($node->condition),
                $this->cloneNode($node->thenBranch),
                $node->elseBranch !== null ? $this->cloneNode($node->elseBranch) : null,
            ),
            $node instanceof WhileStatement => new WhileStatement(
                $this->cloneNode($node->condition),
                $this->cloneNode($node->body),
            ),
            $node instanceof DoWhileStatement => new DoWhileStatement(
                $this->cloneNode($node->body),
                $this->cloneNode($node->condition),
            ),
            $node instanceof ForStatement => new ForStatement(
                $node->init !== null ? $this->cloneNode($node->init) : null,
                $node->condition !== null ? $this->cloneNode($node->condition) : null,
                $node->update !== null ? $this->cloneNode($node->update) : null,
                $this->cloneNode($node->body),
            ),
            $node instanceof SwitchStatement => new SwitchStatement(
                $this->cloneNode($node->expression),
                array_map(fn(CaseClause $c) => $this->cloneCaseClause($c), $node->cases),
            ),
            $node instanceof LabelStatement => new LabelStatement(
                $node->name,
                $this->cloneNode($node->statement),
            ),
            $node instanceof BreakStatement => $node,
            $node instanceof ContinueStatement => $node,
            $node instanceof GotoStatement => $node,

            // Expressions
            $node instanceof IntLiteral => $node,
            $node instanceof FloatLiteral => $node,
            $node instanceof CharLiteralNode => $node,
            $node instanceof StringLiteralNode => $node,
            $node instanceof BoolLiteral => $node,
            $node instanceof NullptrLiteral => $node,
            $node instanceof ThisExpr => $node,
            $node instanceof IdentifierExpr => new IdentifierExpr($node->name, $node->namespacePath),
            $node instanceof BinaryExpr => new BinaryExpr(
                $this->cloneNode($node->left),
                $node->operator,
                $this->cloneNode($node->right),
            ),
            $node instanceof UnaryExpr => new UnaryExpr(
                $node->operator,
                $this->cloneNode($node->operand),
                $node->prefix,
            ),
            $node instanceof AssignExpr => new AssignExpr(
                $this->cloneNode($node->target),
                $node->operator,
                $this->cloneNode($node->value),
            ),
            $node instanceof CallExpr => new CallExpr(
                $this->cloneNode($node->callee),
                array_map(fn(Node $a) => $this->cloneNode($a), $node->arguments),
            ),
            $node instanceof MemberAccessExpr => new MemberAccessExpr(
                $this->cloneNode($node->object),
                $node->member,
                $node->isArrow,
            ),
            $node instanceof ArrayAccessExpr => new ArrayAccessExpr(
                $this->cloneNode($node->array),
                $this->cloneNode($node->index),
            ),
            $node instanceof CastExpr => new CastExpr(
                $this->cloneType($node->targetType),
                $this->cloneNode($node->expression),
                $node->castKind,
            ),
            $node instanceof TernaryExpr => new TernaryExpr(
                $this->cloneNode($node->condition),
                $this->cloneNode($node->trueExpr),
                $this->cloneNode($node->falseExpr),
            ),
            $node instanceof SizeofExpr => new SizeofExpr(
                $node->operand instanceof TypeNode
                    ? $this->cloneType($node->operand)
                    : $this->cloneNode($node->operand),
            ),
            $node instanceof NewExpr => new NewExpr(
                $this->cloneType($node->type),
                array_map(fn(Node $a) => $this->cloneNode($a), $node->arguments),
                $node->isArray,
                $node->arraySize !== null ? $this->cloneNode($node->arraySize) : null,
            ),
            $node instanceof DeleteExpr => new DeleteExpr(
                $this->cloneNode($node->operand),
                $node->isArray,
            ),
            $node instanceof CommaExpr => new CommaExpr(
                array_map(fn(Node $e) => $this->cloneNode($e), $node->expressions),
            ),
            $node instanceof InitializerList => new InitializerList(
                array_map(fn(Node $v) => $this->cloneNode($v), $node->values),
            ),
            $node instanceof ScopeResolutionExpr => new ScopeResolutionExpr(
                $node->scope,
                $node->name,
            ),
            $node instanceof TypeNode => $this->cloneType($node),

            default => $node,
        };
    }

    private function cloneVarDecl(VarDeclaration $node): VarDeclaration
    {
        return new VarDeclaration(
            type: $this->cloneType($node->type),
            name: $node->name,
            initializer: $node->initializer !== null ? $this->cloneNode($node->initializer) : null,
            isArray: $node->isArray,
            arraySize: $node->arraySize !== null ? $this->cloneNode($node->arraySize) : null,
            arrayInit: $node->arrayInit !== null
                ? new InitializerList(array_map(fn(Node $v) => $this->cloneNode($v), $node->arrayInit->values))
                : null,
        );
    }

    private function cloneParameter(Parameter $node): Parameter
    {
        return new Parameter(
            type: $this->cloneType($node->type),
            name: $node->name,
            defaultValue: $node->defaultValue !== null ? $this->cloneNode($node->defaultValue) : null,
        );
    }

    private function cloneFunctionDecl(FunctionDeclaration $node): FunctionDeclaration
    {
        $name = $node->name;
        $className = $node->className;
        $isConstructor = $node->isConstructor;
        $isDestructor = $node->isDestructor;

        // Rename constructor/destructor to match new class name
        if ($node->isConstructor) {
            $name = $this->newClassName;
            if ($className !== null) {
                $className = $this->newClassName;
            }
        } elseif ($node->isDestructor) {
            $name = '~' . $this->newClassName;
            if ($className !== null) {
                $className = $this->newClassName;
            }
        } elseif ($className !== null) {
            $className = $this->newClassName;
        }

        return new FunctionDeclaration(
            returnType: $this->cloneType($node->returnType),
            name: $name,
            parameters: array_map(fn(Parameter $p) => $this->cloneParameter($p), $node->parameters),
            body: $node->body !== null ? $this->cloneBlock($node->body) : null,
            isVirtual: $node->isVirtual,
            isPureVirtual: $node->isPureVirtual,
            isStatic: $node->isStatic,
            isInline: $node->isInline,
            isConst: $node->isConst,
            className: $className,
            isOverride: $node->isOverride,
            isConstructor: $isConstructor,
            isDestructor: $isDestructor,
            memberInitializers: $node->memberInitializers !== null
                ? $this->cloneMemberInitList($node->memberInitializers)
                : null,
        );
    }

    private function cloneOperatorDecl(OperatorDeclaration $node): OperatorDeclaration
    {
        $className = $node->className;
        if ($className !== null) {
            $className = $this->newClassName;
        }

        return new OperatorDeclaration(
            returnType: $this->cloneType($node->returnType),
            operatorSymbol: $node->operatorSymbol,
            parameters: array_map(fn(Parameter $p) => $this->cloneParameter($p), $node->parameters),
            body: $node->body !== null ? $this->cloneBlock($node->body) : null,
            isVirtual: $node->isVirtual,
            isConst: $node->isConst,
            className: $className,
        );
    }

    private function cloneMemberInitList(MemberInitializerList $node): MemberInitializerList
    {
        return new MemberInitializerList(
            array_map(fn(MemberInitializer $m) => $this->cloneMemberInit($m), $node->initializers),
        );
    }

    private function cloneMemberInit(MemberInitializer $node): MemberInitializer
    {
        return new MemberInitializer(
            $node->name,
            array_map(fn(Node $a) => $this->cloneNode($a), $node->arguments),
        );
    }

    private function cloneBlock(BlockStatement $node): BlockStatement
    {
        return new BlockStatement(
            array_map(fn(Node $s) => $this->cloneNode($s), $node->statements),
        );
    }

    private function cloneCaseClause(CaseClause $node): CaseClause
    {
        return new CaseClause(
            $node->value !== null ? $this->cloneNode($node->value) : null,
            array_map(fn(Node $s) => $this->cloneNode($s), $node->statements),
            $node->isDefault,
        );
    }
}
