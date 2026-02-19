<?php

declare(strict_types=1);

namespace Cppc\IR;

class IRParam
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $size = 8,
        public readonly bool $isFloat = false,
    ) {}
}
