<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

/**
 * Itanium C++ ABI name mangling.
 *
 * Reference: https://itanium-cxx-abi.github.io/cxx-abi/abi.html#mangling
 *
 * Supported subset:
 *   Free function:  _Z <len> <name> <param-types...>
 *   Method:         _ZN <class-len> <class> <name-len> <name> E <param-types...>
 *   Constructor:    _ZN <class-len> <class> C1 Ev
 *   Destructor:     _ZN <class-len> <class> D1 Ev
 *   VTable symbol:  _ZTV <class-len> <class>
 */
class Mangler
{
    /** @param TypeNode[] $paramTypes */
    public function mangle(string $name, ?string $className, array $paramTypes): string
    {
        // main is never mangled.
        if ($name === 'main' && $className === null) {
            return 'main';
        }

        // Runtime intrinsics use C linkage (no mangling).
        if ($className === null && str_starts_with($name, '__cppc_')) {
            return $name;
        }

        $paramStr = $this->mangleParams($paramTypes);

        if ($className !== null) {
            return '_ZN'
                . strlen($className) . $className
                . strlen($name) . $name
                . 'E'
                . $paramStr;
        }

        return '_Z' . strlen($name) . $name . $paramStr;
    }

    /** @param TypeNode[] $paramTypes */
    private function mangleParams(array $paramTypes): string
    {
        if (empty($paramTypes)) {
            return 'v'; // void parameter list
        }
        $result = '';
        foreach ($paramTypes as $type) {
            $result .= $this->mangleType($type);
        }
        return $result;
    }

    /**
     * Mangle a single TypeNode to its Itanium type code.
     *
     * Qualifiers are encoded outside-in: const → K, pointer → P, reference → R.
     */
    public function mangleType(TypeNode $type): string
    {
        if ($type->isReference) {
            $inner = clone $type;
            $inner->isReference = false;
            return 'R' . $this->mangleType($inner);
        }

        if ($type->pointerDepth > 0) {
            $inner = clone $type;
            $inner->pointerDepth--;
            // const on a pointer-to-T means "pointer to const T", encoded as P K T;
            // top-level const on the pointer itself is different, but approximated here.
            return 'P' . $this->mangleType($inner);
        }

        if ($type->isConst) {
            $inner = clone $type;
            $inner->isConst = false;
            return 'K' . $this->mangleType($inner);
        }

        if ($type->isUnsigned) {
            return match ($type->baseName) {
                'int'   => 'j',   // unsigned int
                'long'  => 'm',   // unsigned long
                'char'  => 'h',   // unsigned char
                'short' => 't',   // unsigned short
                default => 'j',
            };
        }

        // isLong/isShort may appear as flags rather than as the baseName.
        if ($type->isLong && $type->baseName === 'int') {
            return $type->isUnsigned ? 'm' : 'l';
        }
        if ($type->isShort && $type->baseName === 'int') {
            return $type->isUnsigned ? 't' : 's';
        }

        // Strip struct:/union:/enum: prefixes for mangling — use the plain type name.
        $baseName = $type->baseName;
        if ($type->isStruct()) {
            $baseName = $type->getStructName() ?? $baseName;
        } elseif ($type->isUnion()) {
            $baseName = $type->getUnionName() ?? $baseName;
        } elseif ($type->isEnum()) {
            $baseName = $type->getEnumName() ?? $baseName;
        }

        return match ($baseName) {
            'void'   => 'v',
            'bool'   => 'b',
            'char'   => 'c',
            'short'  => 's',
            'int'    => 'i',
            'long'   => 'l',
            'float'  => 'f',
            'double' => 'd',
            default  => strlen($baseName) . $baseName, // user-defined type
        };
    }

    /**
     * Mangle a constructor. Itanium uses C1 for complete-object constructor.
     *
     * @param TypeNode[] $paramTypes
     */
    public function mangleConstructor(string $className, array $paramTypes = []): string
    {
        $params = $this->mangleParams($paramTypes);
        return '_ZN' . strlen($className) . $className . 'C1' . $params;
    }

    public function mangleDestructor(string $className): string
    {
        return '_ZN' . strlen($className) . $className . 'D1Ev';
    }

    public function mangleVTable(string $className): string
    {
        return '_ZTV' . strlen($className) . $className;
    }
}
