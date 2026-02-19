<?php

declare(strict_types=1);

namespace Cppc\AST;

class OperatorDeclaration extends FunctionDeclaration
{
    public function __construct(
        TypeNode $returnType,
        public readonly string $operatorSymbol,
        array $parameters = [],
        ?BlockStatement $body = null,
        bool $isVirtual = false,
        bool $isConst = false,
        ?string $className = null,
    ) {
        parent::__construct(
            returnType: $returnType,
            name: "operator{$operatorSymbol}",
            parameters: $parameters,
            body: $body,
            isVirtual: $isVirtual,
            isConst: $isConst,
            className: $className,
        );
    }
}
