<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class AsmLine
{
    public ?string $label = null;
    public ?string $mnemonic = null;
    /** @var Operand[] */
    public array $operands = [];
    public ?string $directive = null;
    public mixed $directiveArgs = null;
}
