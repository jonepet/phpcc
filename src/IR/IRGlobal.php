<?php

declare(strict_types=1);

namespace Cppc\IR;

class IRGlobal
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly int $size,
        public readonly ?string $initValue = null,
        public readonly ?string $stringData = null,
        public readonly bool $isLocal = false,
    ) {}
}
