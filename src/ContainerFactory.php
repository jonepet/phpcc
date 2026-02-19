<?php

declare(strict_types=1);

namespace Cppc;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

class ContainerFactory
{
    public static function create(): ContainerInterface
    {
        $builder = new ContainerBuilder();

        $builder->addDefinitions([
            Compiler::class => \DI\create(Compiler::class),
        ]);

        return $builder->build();
    }
}
