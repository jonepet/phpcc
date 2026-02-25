<?php

declare(strict_types=1);

namespace Cppc\IR;

use Cppc\AST\ArrayAccessExpr;
use Cppc\AST\AssignExpr;
use Cppc\AST\BinaryExpr;
use Cppc\AST\BlockStatement;
use Cppc\AST\BoolLiteral;
use Cppc\AST\BreakStatement;
use Cppc\AST\CallExpr;
use Cppc\AST\CastExpr;
use Cppc\AST\CharLiteralNode;
use Cppc\AST\ClassDeclaration;
use Cppc\AST\CommaExpr;
use Cppc\AST\ContinueStatement;
use Cppc\AST\DeleteExpr;
use Cppc\AST\DoWhileStatement;
use Cppc\AST\EnumDeclaration;
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
use Cppc\AST\NamespaceDeclaration;
use Cppc\AST\NewExpr;
use Cppc\AST\Node;
use Cppc\AST\NullptrLiteral;
use Cppc\AST\TemplateDeclaration;
use Cppc\AST\ReturnStatement;
use Cppc\AST\ScopeResolutionExpr;
use Cppc\AST\SizeofExpr;
use Cppc\AST\StringLiteralNode;
use Cppc\AST\SwitchStatement;
use Cppc\AST\TernaryExpr;
use Cppc\AST\ThisExpr;
use Cppc\AST\TranslationUnit;
use Cppc\AST\TypeNode;
use Cppc\AST\UnaryExpr;
use Cppc\AST\VarDeclaration;
use Cppc\AST\WhileStatement;
use Cppc\CompileError;
use Cppc\Semantic\Analyzer;
use Cppc\Semantic\ClassSymbol;
use Cppc\Semantic\FunctionSymbol;

class IRGenerator
{
    private ?IRFunction $currentFunction = null;
    private ?BasicBlock $currentBlock = null;

    /** @var string[] break target label stack */
    private array $breakLabels = [];

    /** @var string[] continue target label stack */
    private array $continueLabels = [];

    /** @var array<string, int> variable name => stack offset per function */
    private array $varMap = [];

    /** @var array<string, bool> set of local variable names that are stack-allocated arrays */
    private array $arrayVars = [];

    /** @var array<string, bool> globally declared variable names */
    private array $globalVars = [];

    /** @var array<string, bool> globally declared array names (for array-to-pointer decay) */
    private array $globalArrays = [];

    /** @var array<string, string> static local name => mangled global name */
    private array $staticLocalMap = [];

    /** @var array<string, int> counter for deduplicating static local mangled names */
    private array $staticLocalCounter = [];

    /** Current class name when generating a method, null otherwise */
    private ?string $currentClassName = null;

    /** @var array<string, string> goto label name => block label */
    private array $namedLabels = [];

    /** @var array<string, BasicBlock> deferred goto patches: label name => block to fix up */
    private array $pendingGotos = [];

    public function __construct(
        private readonly Analyzer $analyzer,
        private readonly IRModule $module,
    ) {}

    public function generate(TranslationUnit $ast): IRModule
    {
        foreach ($ast->declarations as $decl) {
            $this->generateTopLevel($decl);
        }
        return $this->module;
    }

    private function generateTopLevel(Node $node): void
    {
        if ($node instanceof FunctionDeclaration) {
            if (!$node->isForwardDeclaration()) {
                $this->generateFunction($node);
            }
        } elseif ($node instanceof VarDeclaration) {
            $this->generateGlobalVar($node);
        } elseif ($node instanceof ClassDeclaration) {
            if (!$node->isForwardDecl) {
                $this->generateClass($node);
            }
        } elseif ($node instanceof NamespaceDeclaration) {
            foreach ($node->declarations as $decl) {
                $this->generateTopLevel($decl);
            }
        } elseif ($node instanceof EnumDeclaration) {
            // Enum constants are resolved during semantic analysis; no IR needed.
        } elseif ($node instanceof TemplateDeclaration) {
            // Template body is instantiated by Analyzer; skip the template itself.
        }
        // TypedefDeclaration, UsingDeclaration: no direct IR output.
    }

    private function generateFunction(FunctionDeclaration $node): void
    {
        $mangledName = $this->mangleName($node);

        $returnIsFloat = $this->isFloatType($node->returnType);
        $returnSize    = $this->getTypeSize($node->returnType);
        $returnTypeStr = (string)$node->returnType;

        $isLocal = $node->returnType->isStatic || $node->isStatic;
        $irFunc = new IRFunction($mangledName, $returnTypeStr, $returnSize, $returnIsFloat, $isLocal);

        if ($node->className !== null && !$node->isStatic) {
            // Implicit this pointer as first parameter.
            $irFunc->params[] = new IRParam('this', 'ptr', 8, false);
        }
        foreach ($node->parameters as $i => $param) {
            $isFloat = $this->isFloatType($param->type);
            $size    = $this->getTypeSize($param->type);
            $irFunc->params[] = new IRParam($param->name, (string)$param->type, $size, $isFloat);
        }

        $this->module->addFunction($irFunc);

        $prev              = $this->currentFunction;
        $prevBlock         = $this->currentBlock;
        $prevVarMap        = $this->varMap;
        $prevArrayVars     = $this->arrayVars;
        $prevNamedLabels   = $this->namedLabels;
        $prevPendingGotos  = $this->pendingGotos;
        $prevClassName     = $this->currentClassName;

        $this->currentFunction  = $irFunc;
        $this->currentClassName = $node->className;
        $this->varMap           = [];
        $this->arrayVars        = [];
        $this->staticLocalMap   = [];
        $this->staticLocalCounter = [];
        $this->namedLabels      = [];
        $this->pendingGotos     = [];

        // Use mangled name to avoid duplicate 'entry' labels across functions.
        $entryBlock = $irFunc->createBlock('.L' . $mangledName . '_entry');
        $this->switchBlock($entryBlock);

        if ($node->className !== null && !$node->isStatic) {
            $offset = $irFunc->allocLocal('this', 8);
            $this->varMap['this'] = $offset;
            $dst = Operand::stackSlot($offset);
            $src = Operand::param(0);
            $this->emit(new Instruction(OpCode::Store, $dst, $src, line: $node->line));
        }
        foreach ($node->parameters as $i => $param) {
            $size   = $this->getTypeSize($param->type);
            $offset = $irFunc->allocLocal($param->name, $size);
            $this->varMap[$param->name] = $offset;
            $paramIdx = ($node->className !== null && !$node->isStatic) ? $i + 1 : $i;
            $dst = Operand::stackSlot($offset);
            $src = Operand::param($paramIdx);
            $this->emit(new Instruction(OpCode::Store, $dst, $src, line: $param->line));
        }

        if ($node->isConstructor && $node->memberInitializers !== null) {
            foreach ($node->memberInitializers->initializers as $init) {
                $this->generateMemberInitializer($init, $node);
            }
        }

        if ($node->body !== null) {
            $this->generateBlock($node->body);
        }

        // Ensure the last block ends with a return.
        if ($this->currentBlock !== null && !$this->blockIsTerminated($this->currentBlock)) {
            if ($node->returnType->isVoid()) {
                $this->emit(new Instruction(OpCode::Return_, line: $node->line));
            } else {
                $zero = $returnIsFloat ? Operand::floatImm(0.0) : Operand::imm(0);
                $this->emit(new Instruction(OpCode::Return_, null, $zero, line: $node->line));
            }
        }

        $this->currentFunction = $prev;
        $this->currentBlock    = $prevBlock;
        $this->varMap          = $prevVarMap;
        $this->arrayVars       = $prevArrayVars;
        $this->namedLabels     = $prevNamedLabels;
        $this->pendingGotos    = $prevPendingGotos;
        $this->currentClassName = $prevClassName;
    }

    private function generateMemberInitializer(MemberInitializer $init, FunctionDeclaration $ctor): void
    {
        $thisAddr = $this->loadThisPointer($ctor->line);

        $className = $ctor->className ?? '';
        $memberOffset = 0;
        if ($className !== '') {
            $classSym = $this->analyzer->getSymbolTable()->lookupClass($className);
            if ($classSym !== null) {
                $memberSym = $classSym->findMember($init->name);
                if ($memberSym !== null) {
                    $memberOffset = $memberSym->offset;
                }
            }
        }

        if (count($init->arguments) === 1) {
            $val = $this->generateExpression($init->arguments[0]);
            $offsetOp = Operand::imm($memberOffset);
            $ptr = $this->newVReg();
            $this->emit(new Instruction(OpCode::GetElementPtr, $ptr, $thisAddr, $offsetOp));
            $this->emit(new Instruction(OpCode::Store, $ptr, $val));
        } elseif (count($init->arguments) > 1) {
            // Constructor call for a member sub-object — pass sub-object pointer as this.
            $offsetOp = Operand::imm($memberOffset);
            $ptr = $this->newVReg();
            $this->emit(new Instruction(OpCode::GetElementPtr, $ptr, $thisAddr, $offsetOp));
            $args = [$ptr];
            foreach ($init->arguments as $arg) {
                $args[] = $this->generateExpression($arg);
            }
            $this->emitCall($init->name . '_ctor', $args, null);
        }
        // Zero arguments: default-initialize (no instruction needed for scalars).
    }

    private function generateGlobalVar(VarDeclaration $node): void
    {
        // extern declarations don't allocate storage — the symbol is provided by a library.
        if ($node->type->isExtern) {
            $this->globalVars[$node->name] = true;
            return;
        }

        $size      = $this->getTypeSize($node->type);
        $typeStr   = (string)$node->type;
        $initValue = null;

        // Multiply by array count for array declarations.
        if ($node->isArray && $node->arraySize instanceof IntLiteral) {
            $size *= $node->arraySize->value;
        } elseif ($node->isArray && $node->arraySize !== null) {
            // Try to evaluate as constant expression
            $arrSizeVal = $this->tryEvalConstant($node->arraySize);
            if ($arrSizeVal !== null) {
                $size *= $arrSizeVal;
            }
        }

        // Track global arrays for array-to-pointer decay.
        if ($node->isArray) {
            $this->globalArrays[$node->name] = true;
        }

        if ($node->initializer instanceof IntLiteral) {
            $initValue = (string)$node->initializer->value;
        } elseif ($node->initializer instanceof FloatLiteral) {
            $initValue = (string)$node->initializer->value;
        } elseif ($node->initializer instanceof BoolLiteral) {
            $initValue = $node->initializer->value ? '1' : '0';
        } elseif ($node->initializer instanceof CharLiteralNode) {
            $initValue = (string)$node->initializer->ordValue;
        } elseif ($node->initializer instanceof NullptrLiteral) {
            $initValue = '0';
        } elseif ($node->initializer instanceof StringLiteralNode) {
            // String literal → store as a pointer to a .rodata label.
            $label = $this->module->addString($node->initializer->value);
            $initValue = $label;
        }

        $isLocal = $node->type->isStatic;
        $this->module->addGlobal($node->name, $typeStr, $size, $initValue, isLocal: $isLocal);
        $this->globalVars[$node->name] = true;

        // Non-constant initializer: generate an __init function to run at module load.
        if ($node->initializer !== null && $initValue === null) {
            $initFunc = new IRFunction('__init_' . $node->name, 'void', 0, false);
            $this->module->addFunction($initFunc);

            $prev          = $this->currentFunction;
            $prevBlock     = $this->currentBlock;
            $prevVarMap    = $this->varMap;
            $prevArrayVars = $this->arrayVars;

            $this->currentFunction = $initFunc;
            $this->varMap          = [];
            $this->arrayVars       = [];
            $block = $initFunc->createBlock('.L__init_' . $node->name . '_entry');
            $this->switchBlock($block);

            $val = $this->generateExpression($node->initializer);
            $this->emit(new Instruction(OpCode::StoreGlobal, Operand::global($node->name), $val));
            $this->emit(new Instruction(OpCode::Return_));

            $this->currentFunction = $prev;
            $this->currentBlock    = $prevBlock;
            $this->varMap          = $prevVarMap;
            $this->arrayVars       = $prevArrayVars;
        }
    }

    private function generateClass(ClassDeclaration $node): void
    {
        $classSym = $this->analyzer->getSymbolTable()->lookupClass($node->name);
        if ($classSym !== null && count($classSym->vtable) > 0) {
            $vtableEntries = [];
            foreach ($classSym->vtable as $slot => $method) {
                $mangledName = "{$method->className}__{$method->name}";
                $vtableEntries[] = $mangledName;
            }
            $vtableLabel = '__vtable_' . $node->name;
            $this->module->vtables[$vtableLabel] = [
                'entries' => $vtableEntries,
                'size'    => count($vtableEntries) * 8,
            ];
        }

        foreach ($node->members as $member) {
            if ($member instanceof FunctionDeclaration) {
                if (!$member->isForwardDeclaration() && !$member->isPureVirtual) {
                    // Attach className if not already set.
                    $withClass = new FunctionDeclaration(
                        returnType: $member->returnType,
                        name: $member->name,
                        parameters: $member->parameters,
                        body: $member->body,
                        isVirtual: $member->isVirtual,
                        isPureVirtual: $member->isPureVirtual,
                        isStatic: $member->isStatic,
                        isInline: $member->isInline,
                        isConst: $member->isConst,
                        className: $member->className ?? $node->name,
                        isOverride: $member->isOverride,
                        isConstructor: $member->isConstructor,
                        isDestructor: $member->isDestructor,
                        memberInitializers: $member->memberInitializers,
                    );
                    $withClass->setLocation($member->line, $member->column, $member->file);
                    $this->generateFunction($withClass);
                }
            } elseif ($member instanceof VarDeclaration) {
                if ($member->type->isStatic) {
                    $this->generateGlobalVar($member);
                }
                // Non-static members are laid out by the semantic pass; no IR needed here.
            }
        }
    }

    private function generateStatement(Node $stmt): void
    {
        if ($stmt instanceof BlockStatement) {
            $this->generateBlock($stmt);
        } elseif ($stmt instanceof VarDeclaration) {
            $this->generateVarDecl($stmt);
        } elseif ($stmt instanceof ExpressionStatement) {
            $this->generateExpression($stmt->expression);
        } elseif ($stmt instanceof ReturnStatement) {
            $this->generateReturn($stmt);
        } elseif ($stmt instanceof IfStatement) {
            $this->generateIf($stmt);
        } elseif ($stmt instanceof WhileStatement) {
            $this->generateWhile($stmt);
        } elseif ($stmt instanceof ForStatement) {
            $this->generateFor($stmt);
        } elseif ($stmt instanceof DoWhileStatement) {
            $this->generateDoWhile($stmt);
        } elseif ($stmt instanceof SwitchStatement) {
            $this->generateSwitch($stmt);
        } elseif ($stmt instanceof BreakStatement) {
            $this->generateBreak($stmt);
        } elseif ($stmt instanceof ContinueStatement) {
            $this->generateContinue($stmt);
        } elseif ($stmt instanceof GotoStatement) {
            $this->generateGoto($stmt);
        } elseif ($stmt instanceof LabelStatement) {
            $this->generateLabel($stmt);
        } elseif ($stmt instanceof FunctionDeclaration) {
            // Nested function declarations (GNU extension) — generate as top-level.
            if (!$stmt->isForwardDeclaration()) {
                $this->generateFunction($stmt);
            }
        }
    }

    private function generateBlock(BlockStatement $node): void
    {
        foreach ($node->statements as $stmt) {
            // Stop emitting into a terminated block — dead code after return/break/goto.
            if ($this->currentBlock !== null && $this->blockIsTerminated($this->currentBlock)) {
                break;
            }
            $this->generateStatement($stmt);
        }
    }

    private function generateIf(IfStatement $node): void
    {
        assert($this->currentFunction !== null);

        $condOp = $this->generateExpression($node->condition);

        $thenLabel = $this->currentFunction->newLabel('if_then');
        $endLabel  = $this->currentFunction->newLabel('if_end');
        $elseLabel = $node->elseBranch !== null
            ? $this->currentFunction->newLabel('if_else')
            : $endLabel;

        $this->emit(new Instruction(
            OpCode::JumpIfNot,
            null,
            $condOp,
            Operand::label($elseLabel),
            line: $node->line,
        ));

        $thenBlock = $this->newBlock($thenLabel);
        $this->generateStatement($node->thenBranch);

        if (!$this->blockIsTerminated($this->currentBlock)) {
            $this->emit(new Instruction(OpCode::Jump, null, Operand::label($endLabel)));
        }

        if ($node->elseBranch !== null) {
            $this->newBlock($elseLabel);
            $this->generateStatement($node->elseBranch);
            if (!$this->blockIsTerminated($this->currentBlock)) {
                $this->emit(new Instruction(OpCode::Jump, null, Operand::label($endLabel)));
            }
        }

        $this->newBlock($endLabel);
    }

    private function generateWhile(WhileStatement $node): void
    {
        assert($this->currentFunction !== null);

        $headerLabel = $this->currentFunction->newLabel('while_cond');
        $bodyLabel   = $this->currentFunction->newLabel('while_body');
        $endLabel    = $this->currentFunction->newLabel('while_end');

        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($headerLabel)));

        $this->newBlock($headerLabel);
        $condOp = $this->generateExpression($node->condition);
        $this->emit(new Instruction(
            OpCode::JumpIfNot,
            null,
            $condOp,
            Operand::label($endLabel),
            line: $node->line,
        ));

        $this->newBlock($bodyLabel);
        $this->breakLabels[]    = $endLabel;
        $this->continueLabels[] = $headerLabel;
        $this->generateStatement($node->body);
        array_pop($this->breakLabels);
        array_pop($this->continueLabels);

        if (!$this->blockIsTerminated($this->currentBlock)) {
            $this->emit(new Instruction(OpCode::Jump, null, Operand::label($headerLabel)));
        }

        $this->newBlock($endLabel);
    }

    private function generateFor(ForStatement $node): void
    {
        assert($this->currentFunction !== null);

        if ($node->init !== null) {
            $this->generateStatement($node->init);
        }

        $headerLabel = $this->currentFunction->newLabel('for_cond');
        $bodyLabel   = $this->currentFunction->newLabel('for_body');
        $updateLabel = $this->currentFunction->newLabel('for_update');
        $endLabel    = $this->currentFunction->newLabel('for_end');

        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($headerLabel)));

        $this->newBlock($headerLabel);
        if ($node->condition !== null) {
            $condOp = $this->generateExpression($node->condition);
            $this->emit(new Instruction(
                OpCode::JumpIfNot,
                null,
                $condOp,
                Operand::label($endLabel),
                line: $node->line,
            ));
        }

        $this->newBlock($bodyLabel);
        $this->breakLabels[]    = $endLabel;
        $this->continueLabels[] = $updateLabel;
        $this->generateStatement($node->body);
        array_pop($this->breakLabels);
        array_pop($this->continueLabels);

        if (!$this->blockIsTerminated($this->currentBlock)) {
            $this->emit(new Instruction(OpCode::Jump, null, Operand::label($updateLabel)));
        }

        $this->newBlock($updateLabel);
        if ($node->update !== null) {
            $this->generateExpression($node->update);
        }
        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($headerLabel)));

        $this->newBlock($endLabel);
    }

    private function generateDoWhile(DoWhileStatement $node): void
    {
        assert($this->currentFunction !== null);

        $bodyLabel = $this->currentFunction->newLabel('dowhile_body');
        $condLabel = $this->currentFunction->newLabel('dowhile_cond');
        $endLabel  = $this->currentFunction->newLabel('dowhile_end');

        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($bodyLabel)));

        $this->newBlock($bodyLabel);
        $this->breakLabels[]    = $endLabel;
        $this->continueLabels[] = $condLabel;
        $this->generateStatement($node->body);
        array_pop($this->breakLabels);
        array_pop($this->continueLabels);

        if (!$this->blockIsTerminated($this->currentBlock)) {
            $this->emit(new Instruction(OpCode::Jump, null, Operand::label($condLabel)));
        }

        $this->newBlock($condLabel);
        $condOp = $this->generateExpression($node->condition);
        $this->emit(new Instruction(
            OpCode::JumpIf,
            null,
            $condOp,
            Operand::label($bodyLabel),
            line: $node->line,
        ));
        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($endLabel)));

        $this->newBlock($endLabel);
    }

    private function generateSwitch(SwitchStatement $node): void
    {
        assert($this->currentFunction !== null);

        $exprOp  = $this->generateExpression($node->expression);
        $endLabel = $this->currentFunction->newLabel('switch_end');

        $caseLabels   = [];
        $defaultLabel = $endLabel;

        foreach ($node->cases as $i => $case) {
            if ($case->isDefault) {
                $defaultLabel = $this->currentFunction->newLabel('switch_default');
                $caseLabels[$i] = $defaultLabel;
            } else {
                $caseLabels[$i] = $this->currentFunction->newLabel('switch_case');
            }
        }

        foreach ($node->cases as $i => $case) {
            if (!$case->isDefault && $case->value !== null) {
                $caseVal = $this->generateExpression($case->value);
                $cmpReg  = $this->newVReg();
                $this->emit(new Instruction(OpCode::CmpEq, $cmpReg, $exprOp, $caseVal));
                $this->emit(new Instruction(
                    OpCode::JumpIf,
                    null,
                    $cmpReg,
                    Operand::label($caseLabels[$i]),
                ));
            }
        }
        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($defaultLabel)));

        $this->breakLabels[] = $endLabel;

        foreach ($node->cases as $i => $case) {
            $this->newBlock($caseLabels[$i]);
            foreach ($case->statements as $stmt) {
                if ($this->blockIsTerminated($this->currentBlock)) {
                    break;
                }
                $this->generateStatement($stmt);
            }
            // Fall through to next case — do not emit Jump here.
        }

        array_pop($this->breakLabels);
        $this->newBlock($endLabel);
    }

    private function generateBreak(BreakStatement $node): void
    {
        if (empty($this->breakLabels)) {
            throw new CompileError('break outside of loop or switch', $node->file, $node->line, $node->column);
        }
        $label = end($this->breakLabels);
        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($label), line: $node->line));
    }

    private function generateContinue(ContinueStatement $node): void
    {
        if (empty($this->continueLabels)) {
            throw new CompileError('continue outside of loop', $node->file, $node->line, $node->column);
        }
        $label = end($this->continueLabels);
        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($label), line: $node->line));
    }

    private function generateGoto(GotoStatement $node): void
    {
        assert($this->currentFunction !== null);

        if (isset($this->namedLabels[$node->label])) {
            $targetLabel = $this->namedLabels[$node->label];
            $this->emit(new Instruction(OpCode::Jump, null, Operand::label($targetLabel), line: $node->line));
        } else {
            // Forward goto — emit a placeholder and patch the target once the label is defined.
            $placeholder = $this->currentFunction->newLabel('goto_' . $node->label);
            $this->emit(new Instruction(OpCode::Jump, null, Operand::label($placeholder), line: $node->line));
            $this->pendingGotos[$node->label][] = $this->currentBlock;
        }
    }

    private function generateLabel(LabelStatement $node): void
    {
        assert($this->currentFunction !== null);

        $blockLabel = $this->currentFunction->newLabel('lbl_' . $node->name);
        $this->namedLabels[$node->name] = $blockLabel;

        if (isset($this->pendingGotos[$node->name])) {
            foreach ($this->pendingGotos[$node->name] as $fromBlock) {
                $instCount = count($fromBlock->instructions);
                if ($instCount > 0) {
                    $last = $fromBlock->instructions[$instCount - 1];
                    if ($last->opcode === OpCode::Jump) {
                        $fromBlock->instructions[$instCount - 1] = new Instruction(
                            OpCode::Jump,
                            null,
                            Operand::label($blockLabel),
                            line: $last->line,
                        );
                    }
                }
            }
            unset($this->pendingGotos[$node->name]);
        }

        if (!$this->blockIsTerminated($this->currentBlock)) {
            $this->emit(new Instruction(OpCode::Jump, null, Operand::label($blockLabel)));
        }

        $this->newBlock($blockLabel);
        $this->generateStatement($node->statement);
    }

    private function generateReturn(ReturnStatement $node): void
    {
        if ($node->expression !== null) {
            $val = $this->generateExpression($node->expression);
            $this->emit(new Instruction(OpCode::Return_, null, $val, line: $node->line));
        } else {
            $this->emit(new Instruction(OpCode::Return_, line: $node->line));
        }
    }

    private function generateVarDecl(VarDeclaration $node): void
    {
        assert($this->currentFunction !== null);

        // Static local variables are stored as globals with a mangled name.
        if ($node->type->isStatic) {
            $baseMangled = $this->currentFunction->name . '.' . $node->name;
            $counter = ($this->staticLocalCounter[$baseMangled] ?? 0);
            $this->staticLocalCounter[$baseMangled] = $counter + 1;
            $mangledName = $counter === 0 ? $baseMangled : $baseMangled . '.' . $counter;
            $elemSize = $this->getTypeSize($node->type);
            $totalSize = $elemSize;
            $initValue = null;
            $stringData = null;

            if ($node->isArray) {
                if ($node->arraySize instanceof IntLiteral) {
                    $totalSize = $elemSize * $node->arraySize->value;
                } elseif ($node->arraySize !== null) {
                    $arrSizeVal = $this->tryEvalConstant($node->arraySize);
                    if ($arrSizeVal !== null) {
                        $totalSize = $elemSize * $arrSizeVal;
                    }
                }
                $this->globalArrays[$mangledName] = true;
            }

            if ($node->initializer instanceof IntLiteral) {
                $initValue = (string)$node->initializer->value;
            } elseif ($node->initializer instanceof FloatLiteral) {
                $initValue = (string)$node->initializer->value;
            } elseif ($node->initializer instanceof BoolLiteral) {
                $initValue = $node->initializer->value ? '1' : '0';
            } elseif ($node->initializer instanceof CharLiteralNode) {
                $initValue = (string)$node->initializer->ordValue;
            } elseif ($node->initializer instanceof NullptrLiteral) {
                $initValue = '0';
            } elseif ($node->initializer instanceof StringLiteralNode && $node->isArray) {
                $stringData = $node->initializer->value;
                $initValue = '';
            } elseif ($node->initializer instanceof StringLiteralNode) {
                $label = $this->module->addString($node->initializer->value);
                $initValue = $label;
            }

            $this->module->addGlobal($mangledName, (string)$node->type, $totalSize, $initValue, $stringData, isLocal: true);
            $this->globalVars[$mangledName] = true;
            $this->staticLocalMap[$node->name] = $mangledName;
            return;
        }

        $elemSize = $this->getTypeSize($node->type);

        if ($node->isArray && $node->arraySize instanceof IntLiteral) {
            // Allocate the full array on the stack: elemSize * count.
            $count     = $node->arraySize->value;
            $totalSize = $elemSize * $count;
            $offset    = $this->currentFunction->allocLocal($node->name, $totalSize);
            $this->varMap[$node->name]   = $offset;
            $this->arrayVars[$node->name] = true;
            $slot = Operand::stackSlot($offset);
            $this->emit(new Instruction(OpCode::Alloca, $slot, Operand::imm($totalSize), line: $node->line));

            if ($node->arrayInit !== null) {
                // Get the address of the array base via LoadAddr.
                $baseAddr = $this->newVReg();
                $this->emit(new Instruction(OpCode::LoadAddr, $baseAddr, $slot, line: $node->line));
                foreach ($node->arrayInit->values as $idx => $val) {
                    $valOp    = $this->generateExpression($val);
                    $idxOp    = Operand::imm($idx * $elemSize);
                    $elemAddr = $this->newVReg();
                    $this->emit(new Instruction(OpCode::GetElementPtr, $elemAddr, $baseAddr, $idxOp));
                    $this->emit(new Instruction(OpCode::Store, $elemAddr, $valOp));
                }
            }
        } else {
            $offset = $this->currentFunction->allocLocal($node->name, $elemSize);
            $this->varMap[$node->name] = $offset;
            $slot = Operand::stackSlot($offset);
            $this->emit(new Instruction(OpCode::Alloca, $slot, Operand::imm($elemSize), line: $node->line));

            if ($node->initializer !== null) {
                $val = $this->generateExpression($node->initializer);
                $this->emit(new Instruction(OpCode::Store, $slot, $val, line: $node->line));
            } elseif ($node->arrayInit !== null) {
                $baseAddr = $this->newVReg();
                $this->emit(new Instruction(OpCode::LoadAddr, $baseAddr, $slot, line: $node->line));
                $this->generateStructInit($baseAddr, $node->arrayInit, $node->type, $node->line);
            }
        }
    }

    private function generateExpression(Node $expr): Operand
    {
        return match (true) {
            $expr instanceof IntLiteral       => $this->generateIntLiteral($expr),
            $expr instanceof FloatLiteral     => $this->generateFloatLiteral($expr),
            $expr instanceof CharLiteralNode  => $this->generateCharLiteral($expr),
            $expr instanceof StringLiteralNode => $this->generateStringLiteral($expr),
            $expr instanceof BoolLiteral      => $this->generateBoolLiteral($expr),
            $expr instanceof NullptrLiteral   => $this->generateNullptr($expr),
            $expr instanceof IdentifierExpr   => $this->generateIdentifier($expr),
            $expr instanceof BinaryExpr       => $this->generateBinaryExpr($expr),
            $expr instanceof UnaryExpr        => $this->generateUnaryExpr($expr),
            $expr instanceof AssignExpr       => $this->generateAssignExpr($expr),
            $expr instanceof CallExpr         => $this->generateCallExpr($expr),
            $expr instanceof MemberAccessExpr => $this->generateMemberAccess($expr),
            $expr instanceof ArrayAccessExpr  => $this->generateArrayAccess($expr),
            $expr instanceof CastExpr         => $this->generateCast($expr),
            $expr instanceof TernaryExpr      => $this->generateTernary($expr),
            $expr instanceof SizeofExpr       => $this->generateSizeof($expr),
            $expr instanceof NewExpr          => $this->generateNew($expr),
            $expr instanceof DeleteExpr       => $this->generateDelete($expr),
            $expr instanceof CommaExpr        => $this->generateCommaExpr($expr),
            $expr instanceof ThisExpr         => $this->generateThis($expr),
            $expr instanceof ScopeResolutionExpr => $this->generateScopeResolution($expr),
            $expr instanceof InitializerList  => $this->generateInitializerList($expr),
            $expr instanceof DesignatedInit   => $this->generateExpression($expr->value),
            default => throw new CompileError(
                'Unknown expression node: ' . get_class($expr),
                $expr->file,
                $expr->line,
                $expr->column,
            ),
        };
    }

    private function generateIntLiteral(IntLiteral $node): Operand
    {
        return Operand::imm($node->value);
    }

    private function generateFloatLiteral(FloatLiteral $node): Operand
    {
        $dest = $this->newVReg();
        $this->emit(new Instruction(OpCode::LoadFloat, $dest, Operand::floatImm($node->value), line: $node->line));
        return $dest;
    }

    private function generateCharLiteral(CharLiteralNode $node): Operand
    {
        return Operand::imm($node->ordValue);
    }

    private function generateStringLiteral(StringLiteralNode $node): Operand
    {
        $label = $this->module->addString($node->value);
        $dest  = $this->newVReg(8);
        $this->emit(new Instruction(OpCode::LoadString, $dest, Operand::string($label), line: $node->line));
        return $dest;
    }

    private function generateBoolLiteral(BoolLiteral $node): Operand
    {
        return Operand::imm($node->value ? 1 : 0);
    }

    private function generateNullptr(NullptrLiteral $node): Operand
    {
        return Operand::imm(0);
    }

    private function generateIdentifier(IdentifierExpr $node): Operand
    {
        // Enum constants fold to immediates and never reach the stack.
        $sym = $this->analyzer->getSymbolTable()->lookup($node->name);
        if ($sym !== null && $sym->kind === \Cppc\Semantic\SymbolKind::EnumValue) {
            return Operand::imm($sym->enumValue ?? 0);
        }

        // Local variables shadow global function symbols.
        $hasLocal = isset($this->varMap[$node->name])
            || isset($this->staticLocalMap[$node->name])
            || isset($this->arrayVars[$node->name])
            || isset($this->globalVars[$node->name]);

        // Function names used as values: return the function's address (for function pointers).
        if (!$hasLocal && $sym instanceof \Cppc\Semantic\FunctionSymbol) {
            $mangledName = $sym->mangledName !== '' ? $sym->mangledName : $node->name;
            return Operand::funcName($mangledName);
        }

        // Array-to-pointer decay: when a stack-allocated array name is used as a value
        // (e.g. `int* p = data;`), return its address rather than loading the value.
        if (isset($this->arrayVars[$node->name]) && isset($this->varMap[$node->name])) {
            $slot = Operand::stackSlot($this->varMap[$node->name]);
            $dest = $this->newVReg();
            $this->emit(new Instruction(OpCode::LoadAddr, $dest, $slot, line: $node->line));
            return $dest;
        }

        // Array-to-pointer decay for global arrays: return address, not value.
        if (isset($this->globalArrays[$node->name])) {
            $dest = $this->newVReg();
            $this->emit(new Instruction(OpCode::LoadAddr, $dest, Operand::global($node->name), line: $node->line));
            return $dest;
        }

        // Array-to-pointer decay for static local arrays.
        if (isset($this->staticLocalMap[$node->name])) {
            $mangledName = $this->staticLocalMap[$node->name];
            if (isset($this->globalArrays[$mangledName])) {
                $dest = $this->newVReg();
                $this->emit(new Instruction(OpCode::LoadAddr, $dest, Operand::global($mangledName), line: $node->line));
                return $dest;
            }
        }

        $addr = $this->getVarAddress($node->name, $node->line);

        $type = $this->analyzer->getExprType($node);
        $size = $this->getTypeSize($type);

        if ($addr->kind === OperandKind::Global) {
            $dest = $this->newVReg($size);
            $this->emit(new Instruction(OpCode::LoadGlobal, $dest, $addr, line: $node->line));
            return $dest;
        }

        $dest = $this->newVReg($size);
        $this->emit(new Instruction(OpCode::Load, $dest, $addr, line: $node->line));
        return $dest;
    }

    private function generateBinaryExpr(BinaryExpr $node): Operand
    {
        // Short-circuit — handled in dedicated methods.
        if ($node->operator === '&&') {
            return $this->generateLogicalAnd($node);
        }
        if ($node->operator === '||') {
            return $this->generateLogicalOr($node);
        }

        // Pointer arithmetic: p + i or p - i must scale the integer operand by element size.
        if ($node->operator === '+' || $node->operator === '-') {
            $leftType  = $this->inferExpressionType($node->left);
            $rightType = $this->inferExpressionType($node->right);

            $ptrType = null;
            $ptrSide = null; // 'left' or 'right'

            if ($leftType !== null && $leftType->isPointer()) {
                $ptrType = $leftType;
                $ptrSide = 'left';
            } elseif ($rightType !== null && $rightType->isPointer() && $node->operator === '+') {
                // i + p (commutative for addition only)
                $ptrType = $rightType;
                $ptrSide = 'right';
            }

            if ($ptrType !== null) {
                $elemType = new TypeNode(
                    baseName: $ptrType->baseName,
                    isUnsigned: $ptrType->isUnsigned,
                    isSigned: $ptrType->isSigned,
                    isLong: $ptrType->isLong,
                    isShort: $ptrType->isShort,
                    pointerDepth: $ptrType->pointerDepth - 1,
                    className: $ptrType->className,
                );
                $elemSize = $this->getTypeSize($elemType);

                $left  = $this->generateExpression($node->left);
                $right = $this->generateExpression($node->right);

                if ($elemSize > 1) {
                    $intOp = ($ptrSide === 'left') ? $right : $left;
                    $scaled = $this->newVReg();
                    $this->emit(new Instruction(OpCode::Mul, $scaled, $intOp, Operand::imm($elemSize), line: $node->line));
                    if ($ptrSide === 'left') {
                        $right = $scaled;
                    } else {
                        $left = $scaled;
                    }
                }

                $dest = $this->newVReg();
                $opcode = $this->binaryOpcode($node->operator, false);
                $this->emit(new Instruction($opcode, $dest, $left, $right, line: $node->line));
                return $dest;
            }
        }

        $left  = $this->generateExpression($node->left);
        $right = $this->generateExpression($node->right);
        $dest  = $this->newVReg();

        // Bitwise operations are always integer — never float.
        $bitwiseOps = ['&', '|', '^', '<<', '>>', '%'];
        $isBitwise  = in_array($node->operator, $bitwiseOps, true);

        $isFloat = false;
        if (!$isBitwise) {
            $isFloat = $this->isFloatOperand($left) || $this->isFloatOperand($right);

            // Also check via type inference — casts like (double)x produce VRegs
            // that isFloatOperand can't detect (it only checks FloatImm).
            if (!$isFloat) {
                $leftType  = $this->inferExpressionType($node->left);
                $rightType = $this->inferExpressionType($node->right);
                $isFloat = $this->isFloatType($leftType) || $this->isFloatType($rightType);
            }
        }

        $isUnsigned = false;
        if (!$isFloat) {
            $leftType  = $leftType ?? $this->inferExpressionType($node->left);
            $rightType = $rightType ?? $this->inferExpressionType($node->right);
            $isUnsigned = ($leftType !== null && $leftType->isUnsigned)
                       || ($rightType !== null && $rightType->isUnsigned);
        }

        $opcode = $this->binaryOpcode($node->operator, $isFloat, $isUnsigned);
        $this->emit(new Instruction($opcode, $dest, $left, $right, line: $node->line));
        return $dest;
    }

    private function generateLogicalAnd(BinaryExpr $node): Operand
    {
        assert($this->currentFunction !== null);

        $falseLabel = $this->currentFunction->newLabel('and_false');
        $endLabel   = $this->currentFunction->newLabel('and_end');

        $result = $this->newVReg();

        $left = $this->generateExpression($node->left);
        $this->emit(new Instruction(OpCode::JumpIfNot, null, $left, Operand::label($falseLabel)));

        $right = $this->generateExpression($node->right);
        $cmp = $this->newVReg();
        $this->emit(new Instruction(OpCode::CmpNe, $cmp, $right, Operand::imm(0)));
        $this->emit(new Instruction(OpCode::Move, $result, $cmp));
        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($endLabel)));

        $this->newBlock($falseLabel);
        $this->emit(new Instruction(OpCode::Move, $result, Operand::imm(0)));

        $this->newBlock($endLabel);
        return $result;
    }

    private function generateLogicalOr(BinaryExpr $node): Operand
    {
        assert($this->currentFunction !== null);

        $trueLabel = $this->currentFunction->newLabel('or_true');
        $endLabel  = $this->currentFunction->newLabel('or_end');

        $result = $this->newVReg();

        $left = $this->generateExpression($node->left);
        $this->emit(new Instruction(OpCode::JumpIf, null, $left, Operand::label($trueLabel)));

        $right = $this->generateExpression($node->right);
        $cmp   = $this->newVReg();
        $this->emit(new Instruction(OpCode::CmpNe, $cmp, $right, Operand::imm(0)));
        $this->emit(new Instruction(OpCode::Move, $result, $cmp));
        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($endLabel)));

        $this->newBlock($trueLabel);
        $this->emit(new Instruction(OpCode::Move, $result, Operand::imm(1)));

        $this->newBlock($endLabel);
        return $result;
    }

    private function binaryOpcode(string $op, bool $isFloat, bool $isUnsigned = false): OpCode
    {
        if ($isFloat) {
            return match ($op) {
                '+'  => OpCode::FAdd,
                '-'  => OpCode::FSub,
                '*'  => OpCode::FMul,
                '/'  => OpCode::FDiv,
                '==' => OpCode::FCmpEq,
                '!=' => OpCode::FCmpNe,
                '<'  => OpCode::FCmpLt,
                '<=' => OpCode::FCmpLe,
                '>'  => OpCode::FCmpGt,
                '>=' => OpCode::FCmpGe,
                default => throw new \RuntimeException("Unknown float binary operator: {$op}"),
            };
        }

        if ($isUnsigned) {
            $unsignedOp = match ($op) {
                '<'  => OpCode::UCmpLt,
                '<=' => OpCode::UCmpLe,
                '>'  => OpCode::UCmpGt,
                '>=' => OpCode::UCmpGe,
                default => null,
            };
            if ($unsignedOp !== null) {
                return $unsignedOp;
            }
        }

        return match ($op) {
            '+'   => OpCode::Add,
            '-'   => OpCode::Sub,
            '*'   => OpCode::Mul,
            '/'   => OpCode::Div,
            '%'   => OpCode::Mod,
            '=='  => OpCode::CmpEq,
            '!='  => OpCode::CmpNe,
            '<'   => OpCode::CmpLt,
            '<='  => OpCode::CmpLe,
            '>'   => OpCode::CmpGt,
            '>='  => OpCode::CmpGe,
            '&'   => OpCode::And,
            '|'   => OpCode::Or,
            '^'   => OpCode::Xor,
            '<<'  => OpCode::Shl,
            '>>'  => OpCode::Shr,
            default => throw new \RuntimeException("Unknown binary operator: {$op}"),
        };
    }

    private function generateUnaryExpr(UnaryExpr $node): Operand
    {
        if ($node->prefix) {
            return $this->generatePrefixUnary($node);
        }
        return $this->generatePostfixUnary($node);
    }

    private function generatePrefixUnary(UnaryExpr $node): Operand
    {
        switch ($node->operator) {
            case '!': {
                $val  = $this->generateExpression($node->operand);
                $dest = $this->newVReg();
                $this->emit(new Instruction(OpCode::CmpEq, $dest, $val, Operand::imm(0), line: $node->line));
                return $dest;
            }
            case '-': {
                $val  = $this->generateExpression($node->operand);
                $dest = $this->newVReg();
                $isFloat = $this->isFloatOperand($val);
                $opcode  = $isFloat ? OpCode::FNeg : OpCode::Neg;
                $this->emit(new Instruction($opcode, $dest, $val, line: $node->line));
                return $dest;
            }
            case '+':
                // Unary plus is a no-op.
                return $this->generateExpression($node->operand);
            case '~': {
                $val  = $this->generateExpression($node->operand);
                $dest = $this->newVReg();
                $this->emit(new Instruction(OpCode::Not, $dest, $val, line: $node->line));
                return $dest;
            }
            case '*': {
                // Dereference: load from address held in operand.
                $ptr  = $this->generateExpression($node->operand);
                $elemSize = 8;
                $ptrType = $this->inferExpressionType($node->operand);
                if ($ptrType !== null && $ptrType->pointerDepth > 0) {
                    $derefType = new TypeNode(
                        baseName: $ptrType->baseName,
                        pointerDepth: $ptrType->pointerDepth - 1,
                        isUnsigned: $ptrType->isUnsigned,
                        isLong: $ptrType->isLong,
                        isShort: $ptrType->isShort,
                        className: $ptrType->className,
                    );
                    $elemSize = $this->getTypeSize($derefType);
                }
                $dest = $this->newVReg($elemSize);
                $this->emit(new Instruction(OpCode::Load, $dest, $ptr, line: $node->line));
                return $dest;
            }
            case '&': {
                // Address-of: materialize the address of the lvalue into a virtual register.
                $addr = $this->generateLValueAddress($node->operand, $node->line);
                if ($addr->kind === OperandKind::StackSlot) {
                    $dest = $this->newVReg();
                    $this->emit(new Instruction(OpCode::LoadAddr, $dest, $addr, line: $node->line));
                    return $dest;
                }
                if ($addr->kind === OperandKind::Global) {
                    $dest = $this->newVReg();
                    $this->emit(new Instruction(OpCode::LoadAddr, $dest, $addr, line: $node->line));
                    return $dest;
                }
                // Already a virtual register (e.g. member address result): use as-is.
                return $addr;
            }
            case '++': {
                $addr = $this->generateLValueAddress($node->operand, $node->line);
                $old  = $this->loadFromAddress($addr, $node->line);
                $new  = $this->newVReg();
                $this->emit(new Instruction(OpCode::Add, $new, $old, Operand::imm(1), line: $node->line));
                $this->storeToAddress($addr, $new, $node->line);
                return $new;
            }
            case '--': {
                $addr = $this->generateLValueAddress($node->operand, $node->line);
                $old  = $this->loadFromAddress($addr, $node->line);
                $new  = $this->newVReg();
                $this->emit(new Instruction(OpCode::Sub, $new, $old, Operand::imm(1), line: $node->line));
                $this->storeToAddress($addr, $new, $node->line);
                return $new;
            }
            default:
                throw new CompileError(
                    "Unknown prefix unary operator: {$node->operator}",
                    $node->file,
                    $node->line,
                    $node->column,
                );
        }
    }

    private function generatePostfixUnary(UnaryExpr $node): Operand
    {
        $addr = $this->generateLValueAddress($node->operand, $node->line);
        $old  = $this->loadFromAddress($addr, $node->line);
        $copy = $this->newVReg();
        $this->emit(new Instruction(OpCode::Move, $copy, $old, line: $node->line));

        $new = $this->newVReg();
        if ($node->operator === '++') {
            $this->emit(new Instruction(OpCode::Add, $new, $old, Operand::imm(1), line: $node->line));
        } elseif ($node->operator === '--') {
            $this->emit(new Instruction(OpCode::Sub, $new, $old, Operand::imm(1), line: $node->line));
        } else {
            throw new CompileError(
                "Unknown postfix unary operator: {$node->operator}",
                $node->file,
                $node->line,
                $node->column,
            );
        }

        $this->storeToAddress($addr, $new, $node->line);
        return $copy; // Return original value (postfix semantics).
    }

    private function generateAssignExpr(AssignExpr $node): Operand
    {
        $addr = $this->generateLValueAddress($node->target, $node->line);
        $targetType = $this->analyzer->getExprType($node->target);
        $memSize = $this->getTypeSize($targetType);

        $bitFieldMember = null;
        if ($node->target instanceof MemberAccessExpr) {
            $objType = $this->inferExpressionType($node->target->object);
            if ($objType !== null) {
                $className = $this->resolveClassName($objType->className ?? $objType->baseName);
                $bitFieldMember = $this->findMemberInHierarchy($className, $node->target->member);
                if ($bitFieldMember !== null && $bitFieldMember->bitWidth === null) {
                    $bitFieldMember = null;
                }
            }
        }

        if ($node->operator === '=') {
            $val = $this->generateExpression($node->value);
            if ($bitFieldMember !== null) {
                $this->generateBitFieldStore($addr, $bitFieldMember, $val, $node->line);
            } else {
                $this->storeToAddress($addr, $val, $node->line, $memSize);
            }
            return $val;
        }

        $current = $this->loadFromAddress($addr, $node->line, $memSize);
        $rhs     = $this->generateExpression($node->value);
        $isFloat = $this->isFloatOperand($current) || $this->isFloatOperand($rhs);
        if (!$isFloat) {
            $isFloat = $this->isFloatType($targetType)
                || $this->isFloatType($this->inferExpressionType($node->value));
        }
        $baseOp  = match ($node->operator) {
            '+=' => $isFloat ? OpCode::FAdd : OpCode::Add,
            '-=' => $isFloat ? OpCode::FSub : OpCode::Sub,
            '*=' => $isFloat ? OpCode::FMul : OpCode::Mul,
            '/=' => $isFloat ? OpCode::FDiv : OpCode::Div,
            '%=' => OpCode::Mod,
            '&=' => OpCode::And,
            '|=' => OpCode::Or,
            '^=' => OpCode::Xor,
            '<<=' => OpCode::Shl,
            '>>=' => OpCode::Shr,
            default => throw new CompileError(
                "Unknown assignment operator: {$node->operator}",
                $node->file,
                $node->line,
                $node->column,
            ),
        };

        $result = $this->newVReg();
        $this->emit(new Instruction($baseOp, $result, $current, $rhs, line: $node->line));
        $this->storeToAddress($addr, $result, $node->line, $memSize);
        return $result;
    }

    private function generateCallExpr(CallExpr $node): Operand
    {
        $argOps = [];
        foreach ($node->arguments as $arg) {
            $argOps[] = $this->generateExpression($arg);
        }

        if ($node->callee instanceof MemberAccessExpr) {
            return $this->generateMethodCall($node->callee, $argOps, $node->line);
        }

        if ($node->callee instanceof IdentifierExpr) {
            $funcName = $node->callee->namespacePath
                ? $node->callee->namespacePath . '::' . $node->callee->name
                : $node->callee->name;
            // Handle GCC __builtin_* intrinsics.
            if (str_starts_with($funcName, '__builtin_')) {
                return $this->generateBuiltinCall($funcName, $argOps, $node);
            }
            // If the callee is a local variable/parameter (in varMap), it must be
            // a function pointer — emit an indirect call.
            if (isset($this->varMap[$funcName])) {
                $fnPtr = $this->generateExpression($node->callee);
                return $this->emitIndirectCall($fnPtr, $argOps, $node->line);
            }
            // Check if this is a global function pointer variable (not a function declaration).
            $sym = $this->analyzer->getSymbolTable()->lookup($funcName);
            if ($sym !== null && !($sym instanceof \Cppc\Semantic\FunctionSymbol)) {
                $fnPtr = $this->generateExpression($node->callee);
                return $this->emitIndirectCall($fnPtr, $argOps, $node->line);
            }
            return $this->emitCall($funcName, $argOps, $node->line);
        }

        if ($node->callee instanceof ScopeResolutionExpr) {
            $funcName = ($node->callee->scope !== null)
                ? $node->callee->scope . '::' . $node->callee->name
                : $node->callee->name;
            return $this->emitCall($funcName, $argOps, $node->line);
        }

        // Indirect call through a function pointer.
        $fnPtr = $this->generateExpression($node->callee);
        return $this->emitIndirectCall($fnPtr, $argOps, $node->line);
    }

    /**
     * Lower a GCC __builtin_* call to an appropriate IR sequence.
     *
     * Three cases:
     *  - __builtin_constant_p  → always 0 (we can't evaluate at compile time)
     *  - __builtin_expect      → return first argument (branch-prediction hint, no-op)
     *  - __builtin_unreachable → call abort()
     *  - Everything else       → strip the "__builtin_" prefix and call the standard function
     *
     * @param Operand[] $argOps  already-generated operands for the arguments
     */
    private function generateBuiltinCall(string $builtinName, array $argOps, CallExpr $node): Operand
    {
        // Mapping of builtins that delegate to a differently-named stdlib function.
        static $aliasMap = [
            '__builtin_bswap16' => '__bswap_16',
            '__builtin_bswap32' => '__bswap_32',
            '__builtin_bswap64' => '__bswap_64',
            '__builtin_unreachable' => 'abort',
        ];

        // __builtin_constant_p — always return 0 (integer false).
        if ($builtinName === '__builtin_constant_p') {
            // Still evaluate the argument for side-effects (matches GCC behaviour).
            // The result is discarded; we return the immediate 0.
            return Operand::imm(0);
        }

        // __builtin_expect(expr, expected) — return first argument; ignore the hint.
        if ($builtinName === '__builtin_expect') {
            // argOps[0] is already generated; just return it.
            return $argOps[0] ?? Operand::imm(0);
        }

        // Builtins with an explicit alias (bswap variants, unreachable→abort).
        if (isset($aliasMap[$builtinName])) {
            return $this->emitCall($aliasMap[$builtinName], $argOps, $node->line);
        }

        // All remaining builtins: strip "__builtin_" prefix → standard function name.
        $stdName = substr($builtinName, strlen('__builtin_'));
        return $this->emitCall($stdName, $argOps, $node->line);
    }

    private function generateMethodCall(MemberAccessExpr $access, array $argOps, int $line): Operand
    {
        if ($access->isArrow) {
            $thisPtr = $this->generateExpression($access->object);
        } else {
            $thisPtr = $this->generateLValueAddress($access->object, $line);
        }

        $allArgs = array_merge([$thisPtr], $argOps);

        $objectType = $this->inferExpressionType($access->object);
        if ($objectType !== null) {
            $className = $this->resolveClassName($objectType->className ?? $objectType->baseName);
            $classSym  = $this->analyzer->getSymbolTable()->lookupClass($className);
            // Search hierarchy for the method.
            $method = null;
            $searchSym = $classSym;
            while ($searchSym !== null) {
                $method = $searchSym->findMethod($access->member);
                if ($method !== null) {
                    break;
                }
                if ($searchSym->baseClass === null) {
                    break;
                }
                $searchSym = $this->analyzer->getSymbolTable()->lookupClass($searchSym->baseClass);
            }
            if ($method !== null && $method->isVirtual && $classSym !== null) {
                return $this->generateVirtualCall($thisPtr, $classSym, $method, $allArgs, $line);
            }
            // Static dispatch — use the class where the method is actually defined.
            if ($method !== null) {
                $defClass = $method->className !== '' ? $method->className : $className;
                $mangledMethod = "{$defClass}__{$access->member}";
                return $this->emitCall($mangledMethod, $allArgs, $line);
            }
        }

        // No method found — the member may be a function pointer data member.
        // Load it from the struct and do an indirect call (without 'this' pointer).
        $fnPtr = $this->generateMemberAccess($access);
        return $this->emitIndirectCall($fnPtr, $argOps, $line);
    }

    private function generateVirtualCall(
        Operand $thisPtr,
        ClassSymbol $classSym,
        FunctionSymbol $method,
        array $allArgs,
        int $line,
    ): Operand {
        // Vtable pointer is at offset 0 of the object.
        $vtablePtr = $this->newVReg();
        $this->emit(new Instruction(OpCode::Load, $vtablePtr, $thisPtr, line: $line));

        $slot = 0;
        foreach ($classSym->vtable as $s => $m) {
            if ($m->name === $method->name) {
                $slot = $s;
                break;
            }
        }

        $slotOffset = Operand::imm($slot * 8);
        $fnPtrAddr  = $this->newVReg();
        $this->emit(new Instruction(OpCode::GetElementPtr, $fnPtrAddr, $vtablePtr, $slotOffset));
        $fnPtr = $this->newVReg();
        $this->emit(new Instruction(OpCode::Load, $fnPtr, $fnPtrAddr, line: $line));

        return $this->emitIndirectCall($fnPtr, $allArgs, $line);
    }

    private function generateMemberAccess(MemberAccessExpr $node): Operand
    {
        $addr = $this->generateMemberAddress($node);

        $type = $this->inferExpressionType($node->object);
        if ($type !== null) {
            $className = $this->resolveClassName($type->className ?? $type->baseName);
            $member = $this->findMemberInHierarchy($className, $node->member);
            if ($member !== null) {
                if ($member->type->isArrayType()) {
                    return $addr;
                }
                if ($member->bitWidth !== null) {
                    return $this->generateBitFieldLoad($addr, $member, $node->line);
                }
            }
        }

        $memberSize = $this->getMemberSize($node->object, $node->member);
        $dest = $this->newVReg($memberSize);
        $this->emit(new Instruction(OpCode::Load, $dest, $addr, line: $node->line));
        return $dest;
    }

    private function generateBitFieldLoad(Operand $addr, \Cppc\Semantic\Symbol $member, int $line): Operand
    {
        $unitSize = $this->getTypeSize($member->type);
        $unit = $this->newVReg($unitSize);
        $this->emit(new Instruction(OpCode::Load, $unit, $addr, line: $line));

        $result = $unit;
        if ($member->bitOffset > 0) {
            $shifted = $this->newVReg(4);
            $this->emit(new Instruction(OpCode::Shr, $shifted, $result, Operand::imm($member->bitOffset), line: $line));
            $result = $shifted;
        }

        $mask = (1 << $member->bitWidth) - 1;
        $masked = $this->newVReg(4);
        $this->emit(new Instruction(OpCode::And, $masked, $result, Operand::imm($mask), line: $line));
        return $masked;
    }

    private function generateBitFieldStore(Operand $addr, \Cppc\Semantic\Symbol $member, Operand $value, int $line): void
    {
        $unitSize = $this->getTypeSize($member->type);
        $unit = $this->newVReg($unitSize);
        $this->emit(new Instruction(OpCode::Load, $unit, $addr, line: $line));

        $mask = (1 << $member->bitWidth) - 1;
        $clearMask = ~($mask << $member->bitOffset);
        $cleared = $this->newVReg(4);
        $this->emit(new Instruction(OpCode::And, $cleared, $unit, Operand::imm($clearMask), line: $line));

        $shifted = $value;
        if ($member->bitOffset > 0) {
            $shifted = $this->newVReg(4);
            $this->emit(new Instruction(OpCode::Shl, $shifted, $value, Operand::imm($member->bitOffset), line: $line));
        }

        $valueMasked = $this->newVReg(4);
        $this->emit(new Instruction(OpCode::And, $valueMasked, $shifted, Operand::imm($mask << $member->bitOffset), line: $line));

        $combined = $this->newVReg(4);
        $this->emit(new Instruction(OpCode::Or, $combined, $cleared, $valueMasked, line: $line));

        $this->storeToAddress($addr, $combined, $line, $unitSize);
    }

    private function generateMemberAddress(MemberAccessExpr $node): Operand
    {
        if ($node->isArrow) {
            $base = $this->generateExpression($node->object);
        } else {
            $base = $this->generateLValueAddress($node->object, $node->line);
        }

        $offset     = $this->getMemberOffset($node->object, $node->member);
        $offsetOp   = Operand::imm($offset);
        $memberAddr = $this->newVReg();
        $this->emit(new Instruction(OpCode::GetElementPtr, $memberAddr, $base, $offsetOp, line: $node->line));
        return $memberAddr;
    }

    private function getMemberOffset(Node $object, string $memberName): int
    {
        $type = $this->inferExpressionType($object);
        if ($type === null) {
            return 0;
        }
        $className = $this->resolveClassName($type->className ?? $type->baseName);
        $member = $this->findMemberInHierarchy($className, $memberName);
        return $member?->offset ?? 0;
    }

    private function getMemberSize(Node $object, string $memberName): int
    {
        $type = $this->inferExpressionType($object);
        if ($type === null) {
            return 8;
        }
        $className = $this->resolveClassName($type->className ?? $type->baseName);
        $member = $this->findMemberInHierarchy($className, $memberName);
        if ($member === null) {
            return 8;
        }
        return $this->getTypeSize($member->type);
    }

    private function resolveClassName(string $className): string
    {
        $classSym = $this->analyzer->getSymbolTable()->lookupClass($className);
        if ($classSym !== null) {
            return $className;
        }
        $tdSym = $this->analyzer->getSymbolTable()->lookupTypedef($className);
        if ($tdSym !== null) {
            $target = $tdSym->targetType;
            return $target->className ?? $target->baseName;
        }
        return $className;
    }

    private function findMemberInHierarchy(string $className, string $memberName): ?\Cppc\Semantic\Symbol
    {
        $className = $this->resolveClassName($className);
        $classSym = $this->analyzer->getSymbolTable()->lookupClass($className);
        while ($classSym !== null) {
            $member = $classSym->findMember($memberName);
            if ($member !== null) {
                return $member;
            }
            if ($classSym->baseClass === null) {
                break;
            }
            $classSym = $this->analyzer->getSymbolTable()->lookupClass($classSym->baseClass);
        }
        return null;
    }

    private function generateArrayAccess(ArrayAccessExpr $node): Operand
    {
        $addr = $this->generateArrayElementAddress($node);
        $elemSize = $this->inferElementSize($node->array);
        $dest = $this->newVReg($elemSize);
        $this->emit(new Instruction(OpCode::Load, $dest, $addr, line: $node->line));
        return $dest;
    }

    private function generateArrayElementAddress(ArrayAccessExpr $node): Operand
    {
        $elemSz = $this->inferElementSize($node->array);

        // Stack-allocated arrays: the stack slot IS the base — use LoadAddr, not Load.
        // Pointer variables: Load gives us the address value directly.
        if ($node->array instanceof IdentifierExpr && isset($this->arrayVars[$node->array->name])) {
            $slot    = Operand::stackSlot($this->varMap[$node->array->name]);
            $base    = $this->newVReg();
            $this->emit(new Instruction(OpCode::LoadAddr, $base, $slot, line: $node->line));
        } elseif ($node->array instanceof IdentifierExpr && isset($this->globalArrays[$node->array->name])) {
            $base = $this->newVReg();
            $this->emit(new Instruction(OpCode::LoadAddr, $base, Operand::global($node->array->name), line: $node->line));
        } else {
            $base = $this->generateExpression($node->array);
        }

        $idx    = $this->generateExpression($node->index);
        $scaled = $this->newVReg();
        $this->emit(new Instruction(OpCode::Mul, $scaled, $idx, Operand::imm($elemSz), line: $node->line));
        $addr = $this->newVReg();
        $this->emit(new Instruction(OpCode::GetElementPtr, $addr, $base, $scaled, line: $node->line));
        return $addr;
    }

    private function inferElementSize(Node $arrayExpr): int
    {
        $type = $this->inferExpressionType($arrayExpr);
        if ($type === null) {
            return 8;
        }
        if ($type->isArrayType() && $type->arrayElementType !== null) {
            return $this->getTypeSize($type->arrayElementType);
        }
        $elemType = new TypeNode(
            baseName: $type->baseName,
            isUnsigned: $type->isUnsigned,
            isSigned: $type->isSigned,
            isLong: $type->isLong,
            isShort: $type->isShort,
            pointerDepth: max(0, $type->pointerDepth - 1),
            className: $type->className,
        );
        return $this->getTypeSize($elemType);
    }

    private function generateCast(CastExpr $node): Operand
    {
        $src  = $this->generateExpression($node->expression);
        $dest = $this->newVReg($this->getTypeSize($node->targetType));

        $srcIsFloat  = $this->isFloatOperand($src);
        $destIsFloat = $this->isFloatType($node->targetType);
        $destIsPtr   = $node->targetType->isPointer();

        if ($srcIsFloat && !$destIsFloat && !$destIsPtr) {
            $this->emit(new Instruction(OpCode::FloatToInt, $dest, $src, line: $node->line));
        } elseif (!$srcIsFloat && $destIsFloat) {
            $this->emit(new Instruction(OpCode::IntToFloat, $dest, $src, line: $node->line));
        } elseif ($destIsPtr || $node->targetType->pointerDepth > 0) {
            $this->emit(new Instruction(OpCode::Bitcast, $dest, $src, line: $node->line));
        } else {
            $destSize = $this->getTypeSize($node->targetType);
            $srcSize  = $src->size;
            if ($destSize > $srcSize) {
                $srcType = $this->inferExpressionType($node->expression);
                $srcIsUnsigned = $srcType !== null && $srcType->isUnsigned;
                $op = ($node->targetType->isUnsigned || $srcIsUnsigned) ? OpCode::ZeroExtend : OpCode::SignExtend;
                $this->emit(new Instruction($op, $dest, $src, line: $node->line));
            } elseif ($destSize < $srcSize) {
                $this->emit(new Instruction(OpCode::Truncate, $dest, $src, line: $node->line));
            } else {
                $this->emit(new Instruction(OpCode::Move, $dest, $src, line: $node->line));
            }
        }

        return $dest;
    }

    private function generateNew(NewExpr $node): Operand
    {
        $elemSize = $this->getTypeSize($node->type);

        // For class types, use the class symbol's computed size.
        if ($node->type->pointerDepth === 0) {
            $className = $node->type->className ?? $node->type->baseName;
            $classSym  = $this->analyzer->getSymbolTable()->lookupClass($className);
            if ($classSym !== null && $classSym->size > 0) {
                $elemSize = $classSym->size;
            }
        }

        if ($node->isArray && $node->arraySize !== null) {
            $count    = $this->generateExpression($node->arraySize);
            $totalSz  = $this->newVReg();
            $this->emit(new Instruction(OpCode::Mul, $totalSz, $count, Operand::imm($elemSize)));
            $sizeArg  = $totalSz;
        } else {
            $sizeArg = Operand::imm($elemSize);
        }

        $ptr = $this->emitCall('__cppc_alloc', [$sizeArg], $node->line);

        if (!$node->isArray && $node->type->pointerDepth === 0) {
            $className = $node->type->className ?? $node->type->baseName;
            $classSym  = $this->analyzer->getSymbolTable()->lookupClass($className);
            if ($classSym !== null) {
                // Initialize vptr if the class has virtual methods.
                if (count($classSym->vtable) > 0) {
                    $vtableLabel = '__vtable_' . $className;
                    $vtableAddr = $this->newVReg();
                    $this->emit(new Instruction(OpCode::LoadString, $vtableAddr, Operand::global($vtableLabel), line: $node->line));
                    $this->emit(new Instruction(OpCode::Store, $ptr, $vtableAddr, line: $node->line));
                }

                $hasCtor = false;
                foreach ($classSym->methods as $m) {
                    if ($m->name === $className || $m->name === '__constructor') {
                        $hasCtor = true;
                        break;
                    }
                }
                if ($hasCtor) {
                    $ctorArgs = [$ptr];
                    foreach ($node->arguments as $arg) {
                        $ctorArgs[] = $this->generateExpression($arg);
                    }
                    $this->emitCall("{$className}__ctor", $ctorArgs, $node->line);
                }
            }
        }

        return $ptr;
    }

    private function generateDelete(DeleteExpr $node): Operand
    {
        $ptr = $this->generateExpression($node->operand);

        if (!$node->isArray) {
            $type = $this->inferExpressionType($node->operand);
            if ($type !== null && $type->pointerDepth === 1) {
                $className = $type->className ?? $type->baseName;
                $classSym  = $this->analyzer->getSymbolTable()->lookupClass($className);
                if ($classSym !== null) {
                    $hasDtor = false;
                    foreach ($classSym->methods as $m) {
                        if ($m->name === '~' . $className || $m->name === '__destructor') {
                            $hasDtor = true;
                            break;
                        }
                    }
                    if ($hasDtor) {
                        $this->emitCall("{$className}__dtor", [$ptr], $node->line);
                    }
                }
            }
        }

        $this->emitCall('__cppc_free', [$ptr], $node->line);

        return Operand::imm(0);
    }

    private function generateSizeof(SizeofExpr $node): Operand
    {
        if ($node->operand instanceof TypeNode) {
            $size = $this->getTypeSize($node->operand);
        } else {
            $type = $this->inferExpressionType($node->operand);
            $size = $type !== null ? $this->getTypeSize($type) : 8;
        }
        return Operand::imm($size);
    }

    private function generateTernary(TernaryExpr $node): Operand
    {
        assert($this->currentFunction !== null);

        $falseLabel  = $this->currentFunction->newLabel('ternary_false');
        $endLabel    = $this->currentFunction->newLabel('ternary_end');

        $condOp = $this->generateExpression($node->condition);
        $this->emit(new Instruction(
            OpCode::JumpIfNot,
            null,
            $condOp,
            Operand::label($falseLabel),
            line: $node->line,
        ));

        $trueLabel = $this->currentFunction->newLabel('ternary_true');
        $this->newBlock($trueLabel);
        $trueVal = $this->generateExpression($node->trueExpr);
        $result  = $this->newVReg($trueVal->size);
        $this->emit(new Instruction(OpCode::Move, $result, $trueVal));
        $this->emit(new Instruction(OpCode::Jump, null, Operand::label($endLabel)));

        $this->newBlock($falseLabel);
        $falseVal = $this->generateExpression($node->falseExpr);
        $this->emit(new Instruction(OpCode::Move, $result, $falseVal));

        $this->newBlock($endLabel);
        return $result;
    }

    private function generateCommaExpr(CommaExpr $node): Operand
    {
        $last = Operand::imm(0);
        foreach ($node->expressions as $expr) {
            $last = $this->generateExpression($expr);
        }
        return $last;
    }

    private function generateThis(ThisExpr $node): Operand
    {
        $addr = $this->getVarAddress('this', $node->line);
        $dest = $this->newVReg();
        $this->emit(new Instruction(OpCode::Load, $dest, $addr, line: $node->line));
        return $dest;
    }

    private function generateScopeResolution(ScopeResolutionExpr $node): Operand
    {
        // Could be a static member, enum value, or a scoped function reference.
        $sym = $this->analyzer->getSymbolTable()->lookup($node->name);
        if ($sym !== null && $sym->kind === \Cppc\Semantic\SymbolKind::EnumValue) {
            return Operand::imm($sym->enumValue ?? 0);
        }

        if ($node->scope !== null) {
            $qualName = $node->scope . '::' . $node->name;
            $sym      = $this->analyzer->getSymbolTable()->lookup($qualName)
                      ?? $this->analyzer->getSymbolTable()->lookup($node->name);
            if ($sym !== null && $sym->kind === \Cppc\Semantic\SymbolKind::EnumValue) {
                return Operand::imm($sym->enumValue ?? 0);
            }
            // Static member variable.
            $mangledGlobal = $node->scope . '__' . $node->name;
            $dest = $this->newVReg();
            $this->emit(new Instruction(OpCode::LoadGlobal, $dest, Operand::global($mangledGlobal)));
            return $dest;
        }

        $dest = $this->newVReg();
        $this->emit(new Instruction(OpCode::LoadGlobal, $dest, Operand::global($node->name)));
        return $dest;
    }

    private function generateInitializerList(InitializerList $node): Operand
    {
        assert($this->currentFunction !== null);

        $totalSize = count($node->values) * 8;
        $slot      = Operand::stackSlot($this->currentFunction->allocLocal('__initlist', $totalSize));
        $this->emit(new Instruction(OpCode::Alloca, $slot, Operand::imm($totalSize)));

        $baseAddr = $this->newVReg();
        $this->emit(new Instruction(OpCode::LoadAddr, $baseAddr, $slot));

        foreach ($node->values as $i => $val) {
            $valOp   = $this->generateExpression($val instanceof DesignatedInit ? $val->value : $val);
            $offset  = Operand::imm($i * 8);
            $elemPtr = $this->newVReg();
            $this->emit(new Instruction(OpCode::GetElementPtr, $elemPtr, $baseAddr, $offset));
            $this->emit(new Instruction(OpCode::Store, $elemPtr, $valOp));
        }

        return $slot;
    }

    private function generateStructInit(Operand $baseAddr, InitializerList $node, TypeNode $type, int $line): void
    {
        $className = $this->resolveClassName($type->className ?? $type->baseName);
        $classSym  = $this->analyzer->getSymbolTable()->lookupClass($className);

        $positionalIndex = 0;
        foreach ($node->values as $val) {
            $member = null;
            if ($val instanceof DesignatedInit) {
                $member = $classSym !== null ? $this->findMemberInHierarchy($className, $val->field) : null;
                $offset = $member?->offset ?? 0;
                $memberSize = $member !== null ? $this->getTypeSize($member->type) : 8;
                $valOp   = $this->generateExpression($val->value);
            } else {
                if ($classSym !== null) {
                    $nonStaticMembers = array_values(array_filter(
                        $classSym->members,
                        static fn($m) => !$m->isStatic,
                    ));
                    $member = $nonStaticMembers[$positionalIndex] ?? null;
                    $offset = $member?->offset ?? ($positionalIndex * 8);
                    $memberSize = $member !== null ? $this->getTypeSize($member->type) : 8;
                } else {
                    $offset = $positionalIndex * 8;
                    $memberSize = 8;
                }
                $valOp   = $this->generateExpression($val);
                $positionalIndex++;
            }

            $elemPtr = $this->newVReg();
            $this->emit(new Instruction(OpCode::GetElementPtr, $elemPtr, $baseAddr, Operand::imm($offset), line: $line));
            if ($member !== null && $member->bitWidth !== null) {
                $this->generateBitFieldStore($elemPtr, $member, $valOp, $line);
            } else {
                $this->storeToAddress($elemPtr, $valOp, $line, $memberSize);
            }
        }
    }

    private function generateLValueAddress(Node $expr, int $line): Operand
    {
        if ($expr instanceof IdentifierExpr) {
            return $this->getVarAddress($expr->name, $line);
        }

        if ($expr instanceof UnaryExpr && $expr->operator === '*' && $expr->prefix) {
            // *ptr: the address is the pointer value itself, not a load of it.
            return $this->generateExpression($expr->operand);
        }

        if ($expr instanceof MemberAccessExpr) {
            return $this->generateMemberAddress($expr);
        }

        if ($expr instanceof ArrayAccessExpr) {
            return $this->generateArrayElementAddress($expr);
        }

        if ($expr instanceof ScopeResolutionExpr) {
            $mangledName = $expr->scope !== null
                ? $expr->scope . '__' . $expr->name
                : $expr->name;
            return Operand::global($mangledName);
        }

        return $this->generateExpression($expr);
    }

    private function loadFromAddress(Operand $addr, int $line, int $memSize = 8): Operand
    {
        if ($addr->kind === OperandKind::Global) {
            $dest = $this->newVReg($memSize);
            $this->emit(new Instruction(OpCode::LoadGlobal, $dest, $addr, line: $line));
            return $dest;
        }
        $dest = $this->newVReg($memSize);
        $this->emit(new Instruction(OpCode::Load, $dest, $addr, line: $line));
        return $dest;
    }

    private function storeToAddress(Operand $addr, Operand $value, int $line, int $memSize = 8): void
    {
        if ($addr->kind === OperandKind::Global) {
            $sizeHint = $memSize < 8 ? Operand::imm($memSize) : null;
            $this->emit(new Instruction(OpCode::StoreGlobal, $addr, $value, $sizeHint, line: $line));
            return;
        }
        $sizeHint = $memSize < 8 ? Operand::imm($memSize) : null;
        $this->emit(new Instruction(OpCode::Store, $addr, $value, $sizeHint, line: $line));
    }

    private function emitCall(string $funcName, array $argOps, ?int $line): Operand
    {
        // Prefer the mangled name from the symbol table when available.
        $sym = $this->analyzer->getSymbolTable()->lookupFunction($funcName);
        $isVariadic = false;
        if ($sym !== null) {
            if ($sym->mangledName !== '') {
                $funcName = $sym->mangledName;
            }
            $isVariadic = $sym->isVariadic;
        }

        $dest = $this->newVReg();
        $inst = new Instruction(
            OpCode::Call,
            $dest,
            Operand::funcName($funcName),
            Operand::imm(count($argOps)),
            extra: $argOps,
            line: $line ?? 0,
        );
        $inst->isVariadicCall = $isVariadic;
        $this->emit($inst);
        return $dest;
    }

    private function emitIndirectCall(Operand $fnPtr, array $argOps, ?int $line): Operand
    {
        $dest = $this->newVReg();
        $this->emit(new Instruction(
            OpCode::Call,
            $dest,
            $fnPtr,
            Operand::imm(count($argOps)),
            extra: $argOps,
            line: $line ?? 0,
        ));
        return $dest;
    }

    private function loadThisPointer(int $line): Operand
    {
        $addr = $this->getVarAddress('this', $line);
        $dest = $this->newVReg();
        $this->emit(new Instruction(OpCode::Load, $dest, $addr, line: $line));
        return $dest;
    }

    // Best-effort type inference — used to select virtual dispatch and compute member offsets.
    private function inferExpressionType(Node $expr): ?TypeNode
    {
        if ($expr instanceof IdentifierExpr) {
            return $this->analyzer->getExprType($expr);
        }
        if ($expr instanceof MemberAccessExpr) {
            $objType = $this->inferExpressionType($expr->object);
            if ($objType === null) {
                return null;
            }
            $className = $this->resolveClassName($objType->className ?? $objType->baseName);
            $classSym  = $this->analyzer->getSymbolTable()->lookupClass($className);
            if ($classSym === null) {
                return null;
            }
            $member = $classSym->findMember($expr->member);
            return $member?->type;
        }
        if ($expr instanceof UnaryExpr && $expr->operator === '*' && $expr->prefix) {
            $inner = $this->inferExpressionType($expr->operand);
            if ($inner !== null && $inner->pointerDepth > 0) {
                return new TypeNode(
                    baseName: $inner->baseName,
                    pointerDepth: $inner->pointerDepth - 1,
                    className: $inner->className,
                );
            }
        }
        if ($expr instanceof ArrayAccessExpr) {
            return $this->analyzer->getExprType($expr);
        }
        if ($expr instanceof CastExpr) {
            return $expr->targetType;
        }
        if ($expr instanceof IntLiteral) {
            return TypeNode::int();
        }
        if ($expr instanceof FloatLiteral) {
            return TypeNode::double();
        }
        if ($expr instanceof CharLiteralNode) {
            return TypeNode::char();
        }
        if ($expr instanceof BoolLiteral) {
            return TypeNode::bool();
        }
        if ($expr instanceof StringLiteralNode) {
            return new TypeNode(baseName: 'char', pointerDepth: 1);
        }
        if ($expr instanceof NullptrLiteral) {
            return new TypeNode(baseName: 'void', pointerDepth: 1);
        }
        if ($expr instanceof ThisExpr) {
            if ($this->currentClassName !== null) {
                return new TypeNode(baseName: $this->currentClassName, pointerDepth: 1, className: $this->currentClassName);
            }
            return new TypeNode(baseName: 'void', pointerDepth: 1);
        }
        if ($expr instanceof BinaryExpr) {
            $lt = $this->inferExpressionType($expr->left);
            $rt = $this->inferExpressionType($expr->right);
            if ($this->isFloatType($lt) || $this->isFloatType($rt)) {
                return TypeNode::double();
            }
            if ($lt !== null && $lt->isUnsigned) {
                return $lt;
            }
            return $lt ?? $rt;
        }
        if ($expr instanceof CallExpr) {
            return $this->analyzer->getExprType($expr);
        }
        if ($expr instanceof SizeofExpr) {
            return new TypeNode(baseName: 'unsigned long');
        }
        // Fall back to the semantic analyzer's recorded type for any other
        // expression (TernaryExpr, CompoundAssignExpr, PostfixExpr, etc.).
        return $this->analyzer->getExprType($expr);
    }

    private function mangleName(FunctionDeclaration $node): string
    {
        if ($node->className !== null) {
            if ($node->isConstructor) {
                return "{$node->className}__ctor";
            }
            if ($node->isDestructor) {
                return "{$node->className}__dtor";
            }
            return "{$node->className}__{$node->name}";
        }

        $sym = $this->analyzer->getSymbolTable()->lookupFunction($node->name);
        if ($sym !== null && $sym->mangledName !== '') {
            return $sym->mangledName;
        }

        return $node->name;
    }

    private function emit(Instruction $inst): void
    {
        assert($this->currentBlock !== null, 'emit() called with no current block');
        $this->currentBlock->addInstruction($inst);
    }

    private function newBlock(string $label): BasicBlock
    {
        assert($this->currentFunction !== null);
        $block = $this->currentFunction->createBlock($label);
        $this->switchBlock($block);
        return $block;
    }

    private function switchBlock(BasicBlock $block): void
    {
        $this->currentBlock = $block;
    }

    private function newVReg(int $size = 8): Operand
    {
        assert($this->currentFunction !== null);
        return $this->currentFunction->newVReg($size);
    }

    private function getVarAddress(string $name, int $line = 0): Operand
    {
        // Static local variable: redirect to its global storage.
        if (isset($this->staticLocalMap[$name])) {
            return Operand::global($this->staticLocalMap[$name]);
        }

        if (isset($this->varMap[$name])) {
            return Operand::stackSlot($this->varMap[$name]);
        }

        // Inside a method: check if the name is a class member → implicit this->name
        if ($this->currentClassName !== null && isset($this->varMap['this'])) {
            $member = $this->findMemberInHierarchy($this->currentClassName, $name);
            if ($member !== null) {
                return $this->generateImplicitThisMemberAddr($name, $member->offset, $line);
            }
        }

        if (isset($this->globalVars[$name])) {
            return Operand::global($name);
        }

        // Consult the symbol table to catch globals not yet registered (e.g. forward-declared externals).
        $sym = $this->analyzer->getSymbolTable()->lookup($name);
        if ($sym !== null) {
            if ($sym->isStatic || $sym->kind === \Cppc\Semantic\SymbolKind::Variable) {
                return Operand::global($name);
            }
        }

        return Operand::global($name);
    }

    private function generateImplicitThisMemberAddr(string $name, int $offset, int $line): Operand
    {
        $thisPtr = $this->loadThisPointer($line);
        $offsetOp = Operand::imm($offset);
        $addr = $this->newVReg();
        $this->emit(new Instruction(OpCode::GetElementPtr, $addr, $thisPtr, $offsetOp, line: $line));
        return $addr;
    }

    public function isFloatType(?TypeNode $type): bool
    {
        if ($type === null) {
            return false;
        }
        return $type->isFloatingPoint();
    }

    private int $typeSizeDepth = 0;

    public function getTypeSize(?TypeNode $type): int
    {
        if ($type === null) {
            return 8;
        }
        if ($this->typeSizeDepth > 10) {
            return 8; // Bail on deep/circular typedef chains.
        }
        $this->typeSizeDepth++;
        try {
            // Pointers are always 8 bytes regardless of pointee type.
            if ($type->isPointer()) {
                return 8;
            }
            // For struct/union types, look up the class symbol to get the computed layout size.
            if ($type->isStruct() || $type->isUnion()) {
                $name = $type->getStructName() ?? $type->getUnionName();
                if ($name !== null) {
                    $classSym = $this->analyzer->getSymbolTable()->lookupClass($name);
                    if ($classSym !== null && $classSym->size > 0) {
                        return $classSym->size;
                    }
                }
            }
            // For user-defined types (typedef aliases, class names without prefix), try class lookup.
            if (!$type->isPointer() && !$type->isNumeric() && !$type->isVoid()
                && !$type->isFloatingPoint() && !$type->isArrayType() && !$type->isFunctionPointer()
            ) {
                $name = $type->className ?? $type->baseName;
                $classSym = $this->analyzer->getSymbolTable()->lookupClass($name);
                if ($classSym !== null && $classSym->size > 0) {
                    return $classSym->size;
                }
                // Resolve typedef: if the name is a typedef, recurse with the target type.
                $typedefSym = $this->analyzer->getSymbolTable()->lookupTypedef($name);
                if ($typedefSym !== null && $typedefSym->targetType->baseName !== $name) {
                    return $this->getTypeSize($typedefSym->targetType);
                }
            }
            return $type->sizeInBytes();
        } finally {
            $this->typeSizeDepth--;
        }
    }

    private function tryEvalConstant(Node $expr): ?int
    {
        if ($expr instanceof IntLiteral) {
            return $expr->value;
        }
        if ($expr instanceof BinaryExpr) {
            $l = $this->tryEvalConstant($expr->left);
            $r = $this->tryEvalConstant($expr->right);
            if ($l !== null && $r !== null) {
                return match ($expr->operator) {
                    '+' => $l + $r,
                    '-' => $l - $r,
                    '*' => $l * $r,
                    '/' => $r !== 0 ? intdiv($l, $r) : null,
                    default => null,
                };
            }
        }
        if ($expr instanceof SizeofExpr) {
            if ($expr->targetType !== null) {
                return $this->getTypeSize($expr->targetType);
            }
        }
        return null;
    }

    private function isFloatOperand(Operand $op): bool
    {
        return $op->kind === OperandKind::FloatImm;
    }

    private function blockIsTerminated(?BasicBlock $block): bool
    {
        if ($block === null) {
            return false;
        }
        if (empty($block->instructions)) {
            return false;
        }
        $last = end($block->instructions);
        return in_array($last->opcode, [
            OpCode::Jump,
            OpCode::JumpIf,
            OpCode::JumpIfNot,
            OpCode::Return_,
        ], true);
    }
}
