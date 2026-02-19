<?php

declare(strict_types=1);

namespace Cppc\AST;

class TypeNode extends Node
{
    /** @var TypeNode|null Element type for array types */
    public ?TypeNode $arrayElementType = null;

    /** @var int|null Fixed size for array types (null = unsized) */
    public ?int $arraySizeValue = null;

    /** @var TypeNode|null Return type for function pointer types */
    public ?TypeNode $funcPtrReturnType = null;

    /** @var TypeNode[]|null Parameter types for function pointer types */
    public ?array $funcPtrParamTypes = null;

    public function __construct(
        public string $baseName = 'int',
        public bool $isConst = false,
        public bool $isUnsigned = false,
        public bool $isSigned = false,
        public bool $isLong = false,
        public bool $isShort = false,
        public bool $isStatic = false,
        public bool $isExtern = false,
        public bool $isInline = false,
        public bool $isVirtual = false,
        public int $pointerDepth = 0,
        public bool $isReference = false,
        public ?TypeNode $templateParam = null,
        public ?string $className = null,
        public ?string $namespacePath = null,
        public bool $isArray = false,
        public ?Node $arraySize = null,
    ) {}

    public function dump(int $indent = 0): string
    {
        $parts = [];
        if ($this->isConst) $parts[] = 'const';
        if ($this->isStatic) $parts[] = 'static';
        if ($this->isExtern) $parts[] = 'extern';
        if ($this->isVirtual) $parts[] = 'virtual';
        if ($this->isUnsigned) $parts[] = 'unsigned';
        if ($this->isSigned) $parts[] = 'signed';
        if ($this->isLong) $parts[] = 'long';
        if ($this->isShort) $parts[] = 'short';

        if ($this->isArrayType()) {
            $sizeStr = $this->arraySizeValue !== null ? (string) $this->arraySizeValue : '';
            $parts[] = $this->arrayElementType->__toString() . "[{$sizeStr}]";
        } elseif ($this->isFunctionPointer()) {
            $paramStrs = array_map(fn(TypeNode $p) => $p->__toString(), $this->funcPtrParamTypes);
            $parts[] = $this->funcPtrReturnType->__toString() . '(*)(' . implode(', ', $paramStrs) . ')';
        } else {
            $parts[] = $this->baseName;
        }

        if ($this->pointerDepth > 0 && !$this->isFunctionPointer()) {
            $parts[] = str_repeat('*', $this->pointerDepth);
        }
        if ($this->isReference) $parts[] = '&';
        return $this->pad($indent) . 'Type(' . implode(' ', $parts) . ")\n";
    }

    public function isVoid(): bool
    {
        return $this->baseName === 'void' && $this->pointerDepth === 0;
    }

    public function isPointer(): bool
    {
        return $this->pointerDepth > 0;
    }

    public function isInteger(): bool
    {
        if ($this->pointerDepth > 0) {
            return false;
        }
        return in_array($this->baseName, ['int', 'char', 'bool', 'long', 'short'], true)
            || $this->isEnum();
    }

    public function isFloatingPoint(): bool
    {
        return in_array($this->baseName, ['float', 'double'], true)
            && $this->pointerDepth === 0;
    }

    public function isNumeric(): bool
    {
        return $this->isInteger() || $this->isFloatingPoint();
    }

    public function sizeInBytes(): int
    {
        if ($this->pointerDepth > 0 || $this->isReference) return 8;

        // Array type: element size * count (0 if unsized)
        if ($this->isArrayType()) {
            $elemSize = $this->arrayElementType->sizeInBytes();
            return $this->arraySizeValue !== null ? $elemSize * $this->arraySizeValue : 0;
        }

        // Enum is int-sized
        if ($this->isEnum()) return 4;

        // Handle type modifier flags: long int = 8, short int = 2
        if ($this->baseName === 'int') {
            if ($this->isLong) return 8;
            if ($this->isShort) return 2;
            return 4;
        }

        return match ($this->baseName) {
            'char', 'bool' => 1,
            'short' => 2,
            'long' => 8,
            'float' => 4,
            'double' => 8,
            'void' => 0,
            default => 8,
        };
    }

    public function equals(TypeNode $other): bool
    {
        if ($this->baseName !== $other->baseName
            || $this->pointerDepth !== $other->pointerDepth
            || $this->isReference !== $other->isReference
            || $this->isUnsigned !== $other->isUnsigned
            || $this->isConst !== $other->isConst
            || $this->isLong !== $other->isLong
            || $this->isShort !== $other->isShort
            || $this->className !== $other->className
        ) {
            return false;
        }

        // Array types: compare element type and size
        if ($this->isArrayType() || $other->isArrayType()) {
            if (!$this->isArrayType() || !$other->isArrayType()) {
                return false;
            }
            if ($this->arraySizeValue !== $other->arraySizeValue) {
                return false;
            }
            return $this->arrayElementType->equals($other->arrayElementType);
        }

        // Function pointer types: compare return type and param types
        if ($this->isFunctionPointer() || $other->isFunctionPointer()) {
            if (!$this->isFunctionPointer() || !$other->isFunctionPointer()) {
                return false;
            }
            if (!$this->funcPtrReturnType->equals($other->funcPtrReturnType)) {
                return false;
            }
            if (count($this->funcPtrParamTypes) !== count($other->funcPtrParamTypes)) {
                return false;
            }
            foreach ($this->funcPtrParamTypes as $i => $paramType) {
                if (!$paramType->equals($other->funcPtrParamTypes[$i])) {
                    return false;
                }
            }
            return true;
        }

        return true;
    }

    public static function int(): self
    {
        return new self(baseName: 'int');
    }

    public static function char(): self
    {
        return new self(baseName: 'char');
    }

    public static function bool(): self
    {
        return new self(baseName: 'bool');
    }

    public static function void(): self
    {
        return new self(baseName: 'void');
    }

    public static function float_(): self
    {
        return new self(baseName: 'float');
    }

    public static function double(): self
    {
        return new self(baseName: 'double');
    }

    public static function long(): self
    {
        return new self(baseName: 'long');
    }

    public static function charPtr(): self
    {
        return new self(baseName: 'char', pointerDepth: 1);
    }

    // --- Compound type factory methods ---

    public static function struct(string $name): self
    {
        return new self(baseName: "struct:{$name}");
    }

    public static function union(string $name): self
    {
        return new self(baseName: "union:{$name}");
    }

    public static function enum(string $name): self
    {
        return new self(baseName: "enum:{$name}");
    }

    public static function array(TypeNode $elementType, ?int $size): self
    {
        $node = new self(baseName: '__array');
        $node->arrayElementType = $elementType;
        $node->arraySizeValue = $size;
        return $node;
    }

    public static function functionPointer(TypeNode $returnType, array $paramTypes): self
    {
        $node = new self(baseName: '__func_ptr', pointerDepth: 1);
        $node->funcPtrReturnType = $returnType;
        $node->funcPtrParamTypes = $paramTypes;
        return $node;
    }

    // --- Compound type query methods ---

    public function isStruct(): bool
    {
        return str_starts_with($this->baseName, 'struct:');
    }

    public function isUnion(): bool
    {
        return str_starts_with($this->baseName, 'union:');
    }

    public function isEnum(): bool
    {
        return str_starts_with($this->baseName, 'enum:');
    }

    public function isArrayType(): bool
    {
        return $this->baseName === '__array' && $this->arrayElementType !== null;
    }

    public function isFunctionPointer(): bool
    {
        return $this->baseName === '__func_ptr' && $this->funcPtrReturnType !== null;
    }

    public function getStructName(): ?string
    {
        if (!$this->isStruct()) {
            return null;
        }
        return substr($this->baseName, 7); // strlen('struct:') === 7
    }

    public function getUnionName(): ?string
    {
        if (!$this->isUnion()) {
            return null;
        }
        return substr($this->baseName, 6); // strlen('union:') === 6
    }

    public function getEnumName(): ?string
    {
        if (!$this->isEnum()) {
            return null;
        }
        return substr($this->baseName, 5); // strlen('enum:') === 5
    }

    public function getArrayElementType(): ?TypeNode
    {
        return $this->arrayElementType;
    }

    public function getArraySize(): ?int
    {
        return $this->arraySizeValue;
    }

    public function getFuncPtrReturnType(): ?TypeNode
    {
        return $this->funcPtrReturnType;
    }

    /** @return TypeNode[]|null */
    public function getFuncPtrParamTypes(): ?array
    {
        return $this->funcPtrParamTypes;
    }

    public function __toString(): string
    {
        $parts = [];
        if ($this->isConst) $parts[] = 'const';
        if ($this->isUnsigned) $parts[] = 'unsigned';
        if ($this->isSigned) $parts[] = 'signed';
        if ($this->isLong) $parts[] = 'long';
        if ($this->isShort) $parts[] = 'short';
        if ($this->namespacePath) $parts[] = $this->namespacePath . '::';

        if ($this->isArrayType()) {
            $sizeStr = $this->arraySizeValue !== null ? (string) $this->arraySizeValue : '';
            $parts[] = $this->arrayElementType->__toString() . "[{$sizeStr}]";
        } elseif ($this->isFunctionPointer()) {
            $paramStrs = array_map(fn(TypeNode $p) => $p->__toString(), $this->funcPtrParamTypes);
            $parts[] = $this->funcPtrReturnType->__toString() . '(*)(' . implode(', ', $paramStrs) . ')';
        } elseif ($this->isStruct()) {
            $parts[] = 'struct ' . $this->getStructName();
        } elseif ($this->isUnion()) {
            $parts[] = 'union ' . $this->getUnionName();
        } elseif ($this->isEnum()) {
            $parts[] = 'enum ' . $this->getEnumName();
        } else {
            $parts[] = $this->baseName;
        }

        if ($this->pointerDepth > 0 && !$this->isFunctionPointer()) {
            $parts[] = str_repeat('*', $this->pointerDepth);
        }
        if ($this->isReference) $parts[] = '&';
        return implode(' ', $parts);
    }
}
