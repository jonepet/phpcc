<?php

declare(strict_types=1);

namespace Cppc\Assembler;

class SectionData
{
    public string $bytes = '';
    /** @var Relocation[] */
    public array $relocs = [];
    /** @var Symbol[] */
    public array $symbols = [];
    public int $align = 1;

    public function __construct(public string $name) {}
}
