<?php

declare(strict_types=1);

namespace Cppc\Assembler;

enum OperandKind
{
    case Register;
    case Immediate;
    case Memory;
    case MemSib;
    case RipRel;
    case Label;
}
