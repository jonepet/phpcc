<?php

declare(strict_types=1);

namespace Cppc\Assembler;

enum OperandKind
{
    case Register;
    case Immediate;
    case SymbolImm;  // $symbol_name — address of symbol as immediate
    case Memory;
    case MemSib;
    case RipRel;
    case Label;
}
