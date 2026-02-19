<?php

declare(strict_types=1);

namespace Cppc\IR;

enum OperandKind
{
    case VirtualReg;
    case Immediate;
    case FloatImm;
    case Label;
    case Global;
    case String;
    case FuncName;
    case StackSlot;
    case Param;
}
