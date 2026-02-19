<?php

declare(strict_types=1);

namespace Cppc\IR;

enum OpCode: string
{
    // Arithmetic
    case Add = 'add';
    case Sub = 'sub';
    case Mul = 'mul';
    case Div = 'div';
    case Mod = 'mod';
    case Neg = 'neg';

    // Floating point arithmetic
    case FAdd = 'fadd';
    case FSub = 'fsub';
    case FMul = 'fmul';
    case FDiv = 'fdiv';
    case FNeg = 'fneg';

    // Bitwise
    case And = 'and';
    case Or = 'or';
    case Xor = 'xor';
    case Not = 'not';
    case Shl = 'shl';
    case Shr = 'shr';

    // Comparison
    case CmpEq = 'cmpeq';
    case CmpNe = 'cmpne';
    case CmpLt = 'cmplt';
    case CmpLe = 'cmple';
    case CmpGt = 'cmpgt';
    case CmpGe = 'cmpge';

    // Unsigned comparison
    case UCmpLt = 'ucmplt';
    case UCmpLe = 'ucmple';
    case UCmpGt = 'ucmpgt';
    case UCmpGe = 'ucmpge';

    // Float comparison
    case FCmpEq = 'fcmpeq';
    case FCmpNe = 'fcmpne';
    case FCmpLt = 'fcmplt';
    case FCmpLe = 'fcmple';
    case FCmpGt = 'fcmpgt';
    case FCmpGe = 'fcmpge';

    // Memory
    case Load = 'load';
    case Store = 'store';
    case LoadAddr = 'loadaddr';
    case Alloca = 'alloca';
    case GetElementPtr = 'getelementptr';

    // Control flow
    case Jump = 'jmp';
    case JumpIf = 'jmpif';
    case JumpIfNot = 'jmpifnot';
    case Label = 'label';

    // Function
    case Call = 'call';
    case Param = 'param';
    case Return_ = 'ret';

    // Data movement
    case Move = 'mov';
    case LoadImm = 'loadimm';
    case LoadFloat = 'loadfloat';
    case LoadString = 'loadstring';
    case LoadGlobal = 'loadglobal';
    case StoreGlobal = 'storeglobal';

    // Type conversion
    case IntToFloat = 'int2float';
    case FloatToInt = 'float2int';
    case SignExtend = 'sext';
    case ZeroExtend = 'zext';
    case Truncate = 'trunc';
    case Bitcast = 'bitcast';

    // Misc
    case Nop = 'nop';
    case Phi = 'phi';
}
