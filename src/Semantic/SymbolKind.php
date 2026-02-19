<?php

declare(strict_types=1);

namespace Cppc\Semantic;

enum SymbolKind
{
    case Variable;
    case Function;
    case Parameter;
    case Class_;
    case Enum;
    case Namespace;
    case Typedef;
    case EnumValue;
    case Member;
}
