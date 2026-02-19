<?php

declare(strict_types=1);

namespace Cppc\Semantic;

use Cppc\AST\TypeNode;

class TypeSystem
{
    /** @var array<string, StructSymbol> Registered struct/union definitions for size calculations */
    private array $structDefs = [];

    /**
     * Register a struct/union definition for use in size/alignment calculations.
     */
    public function registerStruct(StructSymbol $sym): void
    {
        $prefix = $sym->isUnion ? 'union' : 'struct';
        $this->structDefs["{$prefix}:{$sym->name}"] = $sym;
    }

    public function isCompatible(TypeNode $from, TypeNode $to): bool
    {
        if ($from->equals($to)) {
            return true;
        }
        return $this->canImplicitConvert($from, $to);
    }

    /**
     * Standard implicit conversion rules from C++:
     *   - integer promotions: bool/char/short → int
     *   - numeric widening: int → long, int → float, float → double, etc.
     *   - nullptr_t → any pointer type
     *   - derived class pointer/ref → base class pointer/ref (requires class info, approximated)
     *   - dropping const on non-reference is NOT an implicit conversion here;
     *     adding const on a copy destination is fine but not modelled here
     */
    public function canImplicitConvert(TypeNode $from, TypeNode $to): bool
    {
        if ($from->equals($to)) {
            return true;
        }

        // nullptr can convert to any pointer type.
        if ($from->baseName === 'nullptr_t' && $to->pointerDepth > 0) {
            return true;
        }

        if ($from->baseName === 'void' && $from->pointerDepth === 1 && $to->pointerDepth > 0) {
            return true;
        }

        if ($from->pointerDepth > 0 && $to->baseName === 'void' && $to->pointerDepth === 1) {
            return true;
        }

        if ($from->pointerDepth === 0 && $to->pointerDepth === 0
            && $from->isReference === false && $to->isReference === false
        ) {
            return $this->canImplicitArithmeticConvert($from, $to);
        }

        // Reference binding: T& ← T (non-const), const T& ← T (any).
        if ($to->isReference && !$from->isReference && $from->pointerDepth === 0) {
            $strippedTo = clone $to;
            $strippedTo->isReference = false;
            $strippedToNoConst = clone $strippedTo;
            $strippedToNoConst->isConst = false;
            $strippedFromNoConst = clone $from;
            $strippedFromNoConst->isConst = false;
            if ($strippedToNoConst->equals($strippedFromNoConst)) {
                return true;
            }
            // const ref can bind to anything compatible.
            if ($to->isConst && $this->canImplicitArithmeticConvert($from, $strippedToNoConst)) {
                return true;
            }
        }

        return false;
    }

    private function canImplicitArithmeticConvert(TypeNode $from, TypeNode $to): bool
    {
        $fromRank = $this->arithmeticRank($from);
        $toRank   = $this->arithmeticRank($to);

        if ($fromRank === -1 || $toRank === -1) {
            return false;
        }

        // Widening: can go from lower rank to higher rank (int→float, etc.)
        // Also allow bool/char/short → int (promotions).
        return $toRank >= $fromRank;
    }

    /**
     * Assign a numeric rank for implicit conversion ordering.
     * Higher rank = wider type; floating-point ranks above integral.
     */
    private function arithmeticRank(TypeNode $type): int
    {
        // bool=0, char=1, short=2, int=3, long=4, float=5, double=6
        // unsigned variants share ranks.
        if ($type->baseName === 'bool')   return 0;
        if ($type->baseName === 'char')   return 1;
        if ($type->baseName === 'short' || $type->isShort) return 2;
        if ($type->baseName === 'int')    return 3;
        if ($type->isEnum())              return 3; // enums have int rank
        if ($type->baseName === 'long' || $type->isLong)   return 4;
        if ($type->baseName === 'float')  return 5;
        if ($type->baseName === 'double') return 6;
        return -1; // non-arithmetic
    }

    /**
     * Usual arithmetic conversions: find the common type for a binary operation.
     * Follows C++ [expr]/11: if either operand is double → double, else float → float,
     * else both integral: promote then use the wider/unsigned type.
     */
    public function getCommonType(TypeNode $a, TypeNode $b): TypeNode
    {
        // If one is double, result is double.
        if ($a->baseName === 'double' || $b->baseName === 'double') {
            return TypeNode::double();
        }
        // If one is float, result is float.
        if ($a->baseName === 'float' || $b->baseName === 'float') {
            return TypeNode::float_();
        }

        // Both integral: apply integer promotion first.
        $pa = $this->promoteType($a);
        $pb = $this->promoteType($b);

        $rankA = $this->arithmeticRank($pa);
        $rankB = $this->arithmeticRank($pb);

        if ($rankA === $rankB) {
            // Same rank: if either is unsigned, result is unsigned.
            if ($pa->isUnsigned || $pb->isUnsigned) {
                $result = clone $pa;
                $result->isUnsigned = true;
                $result->isSigned   = false;
                return $result;
            }
            return $pa;
        }

        // Use the wider type; if wider is signed and narrower is unsigned of same rank, unsigned wins.
        $wider   = $rankA > $rankB ? $pa : $pb;
        $narrower = $rankA > $rankB ? $pb : $pa;

        if ($narrower->isUnsigned && !$wider->isUnsigned) {
            // Unsigned narrower has same or higher effective rank in C++ (edge case).
            // For simplicity: if the wider type can represent all values, use wider signed.
            // Otherwise promote to unsigned of the wider type.
            $result = clone $wider;
            // If ranks differ by more than one, signed wider can represent all unsigned narrower values.
            if ($this->arithmeticRank($wider) > $this->arithmeticRank($narrower)) {
                $result->isUnsigned = false;
            } else {
                $result->isUnsigned = true;
                $result->isSigned   = false;
            }
            return $result;
        }

        return $wider;
    }

    /**
     * C++ static_cast rules: can $from be explicitly cast to $to?
     */
    public function canExplicitCast(TypeNode $from, TypeNode $to): bool
    {
        if ($this->canImplicitConvert($from, $to)) {
            return true;
        }

        // Numeric ↔ numeric (any explicit numeric conversion).
        if ($from->isNumeric() && $to->isNumeric()) {
            return true;
        }

        // Pointer ↔ pointer (reinterpret-style, but static_cast allows related class pointers).
        if ($from->pointerDepth > 0 && $to->pointerDepth > 0) {
            return true;
        }

        // Pointer ↔ integer (reinterpret).
        if ($from->pointerDepth > 0 && $to->isInteger()) {
            return true;
        }
        if ($from->isInteger() && $to->pointerDepth > 0) {
            return true;
        }

        // Enum ↔ integer.
        if ($from->baseName === 'int' && $to->baseName !== 'void') {
            return true;
        }

        // void* ↔ any pointer.
        if ($from->baseName === 'void' && $to->pointerDepth > 0) {
            return true;
        }

        // Drop const (const_cast territory, still expressible as static_cast for non-ref).
        if ($from->isConst && !$to->isConst) {
            $fromNoConst = clone $from;
            $fromNoConst->isConst = false;
            if ($fromNoConst->equals($to)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assignment compatibility: can a value of type $from be assigned to a variable of type $to?
     * Equivalent to canImplicitConvert but also handles const-qualified destinations.
     */
    public function isAssignableTo(TypeNode $from, TypeNode $to): bool
    {
        // Cannot assign to a const variable (lvalue), but for type compatibility ignore const
        // on the destination (const on destination just means you can't reassign the variable).
        $toBase = clone $to;
        $toBase->isConst   = false;
        $toBase->isStatic  = false;
        $toBase->isExtern  = false;
        $toBase->isInline  = false;
        $toBase->isVirtual = false;
        $toBase->isReference = false;

        $fromBase = clone $from;
        $fromBase->isConst   = false;
        $fromBase->isStatic  = false;
        $fromBase->isExtern  = false;
        $fromBase->isInline  = false;
        $fromBase->isVirtual = false;
        $fromBase->isReference = false;

        return $this->isCompatible($fromBase, $toBase);
    }

    /**
     * Determine the result type of an operator applied to the given operand types.
     * $right is null for unary operators.
     */
    public function getResultType(string $op, TypeNode $left, ?TypeNode $right = null): TypeNode
    {
        $boolOps = ['==', '!=', '<', '>', '<=', '>=', '&&', '||', '!'];
        if (in_array($op, $boolOps, true)) {
            return TypeNode::bool();
        }

        if ($right === null) {
            return match ($op) {
                '~'         => $this->promoteType($left),
                '-', '+'    => $this->promoteType($left),
                '++', '--'  => $left,
                '*'         => $this->dereferenceType($left),
                '&'         => $this->addressOfType($left),
                default     => $left,
            };
        }

        // Pointer arithmetic: ptr + int → ptr, ptr - int → ptr, ptr - ptr → ptrdiff_t (long).
        if ($left->pointerDepth > 0) {
            if (in_array($op, ['+', '-', '+=', '-='], true)) {
                if ($right->isInteger() || $right->isNumeric()) {
                    return $left;
                }
            }
            if ($op === '-' && $right->pointerDepth > 0) {
                return TypeNode::long(); // ptrdiff_t
            }
        }

        $assignOps = ['=', '+=', '-=', '*=', '/=', '%=', '&=', '|=', '^=', '<<=', '>>='];
        if (in_array($op, $assignOps, true)) {
            return $left;
        }

        $bitwiseOps = ['&', '|', '^', '<<', '>>'];
        if (in_array($op, $bitwiseOps, true)) {
            if ($left->isInteger() && $right->isInteger()) {
                return $this->getCommonType($left, $right);
            }
        }

        $arithOps = ['+', '-', '*', '/', '%'];
        if (in_array($op, $arithOps, true)) {
            return $this->getCommonType($left, $right);
        }

        return $left;
    }

    /**
     * Integer promotion: char/short/bool are promoted to int.
     * Other types are unchanged.
     */
    public function promoteType(TypeNode $type): TypeNode
    {
        if ($type->pointerDepth > 0 || $type->isReference) {
            return $type;
        }
        $name = $type->baseName;
        if ($name === 'bool' || $name === 'char' || $name === 'short' || $type->isShort) {
            return new TypeNode(
                baseName: 'int',
                isUnsigned: $type->isUnsigned,
            );
        }
        return $type;
    }

    private function dereferenceType(TypeNode $type): TypeNode
    {
        if ($type->pointerDepth === 0) {
            // Dereferencing a non-pointer is an error, but return the type for recovery.
            return $type;
        }
        $result = clone $type;
        $result->pointerDepth--;
        return $result;
    }

    private function addressOfType(TypeNode $type): TypeNode
    {
        $result = clone $type;
        $result->pointerDepth++;
        $result->isReference = false;
        return $result;
    }

    // --- Extended type system methods ---

    /**
     * Check if two types are compatible (structural equality for compound types).
     * Struct/union types are compared by name (nominal typing).
     */
    public function areTypesCompatible(TypeNode $a, TypeNode $b): bool
    {
        // Exact match
        if ($a->equals($b)) {
            return true;
        }

        // Struct/union: compare by name only (nominal typing)
        if ($a->isStruct() && $b->isStruct()) {
            return $a->getStructName() === $b->getStructName();
        }
        if ($a->isUnion() && $b->isUnion()) {
            return $a->getUnionName() === $b->getUnionName();
        }

        // Enum: same enum name
        if ($a->isEnum() && $b->isEnum()) {
            return $a->getEnumName() === $b->getEnumName();
        }

        // Array types: element types must be compatible, sizes must match
        if ($a->isArrayType() && $b->isArrayType()) {
            if ($a->getArraySize() !== $b->getArraySize()) {
                return false;
            }
            return $this->areTypesCompatible($a->getArrayElementType(), $b->getArrayElementType());
        }

        // Function pointer types: return type and all param types must be compatible
        if ($a->isFunctionPointer() && $b->isFunctionPointer()) {
            if (!$this->areTypesCompatible($a->getFuncPtrReturnType(), $b->getFuncPtrReturnType())) {
                return false;
            }
            $aParams = $a->getFuncPtrParamTypes();
            $bParams = $b->getFuncPtrParamTypes();
            if (count($aParams) !== count($bParams)) {
                return false;
            }
            foreach ($aParams as $i => $aParam) {
                if (!$this->areTypesCompatible($aParam, $bParams[$i])) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * Check if $from can be implicitly converted to $to.
     * Extends canImplicitConvert with:
     *   - enum -> int (enum values are int-compatible)
     *   - array -> pointer decay (array of T -> pointer to T)
     *   - void* -> any pointer
     *   - any pointer -> void*
     *   - function pointer compatibility
     */
    public function isImplicitlyConvertible(TypeNode $from, TypeNode $to): bool
    {
        // Exact match or existing implicit conversion rules
        if ($this->canImplicitConvert($from, $to)) {
            return true;
        }

        // Enum -> int: enums are implicitly convertible to integers
        if ($from->isEnum() && $to->pointerDepth === 0) {
            if (in_array($to->baseName, ['int', 'long', 'short', 'char', 'bool'], true)) {
                return true;
            }
        }

        // Array -> pointer decay: array of T implicitly converts to pointer to T
        if ($from->isArrayType() && $to->pointerDepth > 0) {
            $decayed = $this->decayArrayToPointer($from);
            if ($decayed->equals($to)) {
                return true;
            }
            // Also allow decay to void*
            if ($to->baseName === 'void' && $to->pointerDepth === 1) {
                return true;
            }
        }

        // void* -> any pointer type
        if ($from->baseName === 'void' && $from->pointerDepth === 1 && $to->pointerDepth > 0) {
            return true;
        }

        // Any pointer -> void*
        if ($from->pointerDepth > 0 && $to->baseName === 'void' && $to->pointerDepth === 1) {
            return true;
        }

        // Function pointer -> function pointer with compatible signatures
        if ($from->isFunctionPointer() && $to->isFunctionPointer()) {
            return $this->areTypesCompatible($from, $to);
        }

        // Null pointer (0 literal as int) -> any pointer
        // (This is already handled in canImplicitConvert for nullptr_t)

        return false;
    }

    /**
     * Compute the size of a type in bytes.
     *
     * - bool/char: 1
     * - short: 2
     * - int/float: 4
     * - long/double/pointer: 8
     * - struct: sum of member sizes with alignment padding
     * - union: max member size
     * - array: elementSize * count
     * - enum: 4 (int-sized)
     */
    public function getTypeSize(TypeNode $type): int
    {
        // Pointers and references are 8 bytes on x86-64
        if ($type->pointerDepth > 0 || $type->isReference) {
            return 8;
        }

        // Array type
        if ($type->isArrayType()) {
            $elemSize = $this->getTypeSize($type->getArrayElementType());
            $count = $type->getArraySize();
            return $count !== null ? $elemSize * $count : 0;
        }

        // Enum is int-sized
        if ($type->isEnum()) {
            return 4;
        }

        // Struct: compute from registered definition
        if ($type->isStruct()) {
            $key = $type->baseName; // "struct:Name"
            if (isset($this->structDefs[$key])) {
                return $this->structDefs[$key]->size;
            }
            // Unknown struct — return 0
            return 0;
        }

        // Union: compute from registered definition
        if ($type->isUnion()) {
            $key = $type->baseName; // "union:Name"
            if (isset($this->structDefs[$key])) {
                return $this->structDefs[$key]->size;
            }
            return 0;
        }

        // Handle compound type specifiers
        $baseName = $this->resolveCompoundBaseName($type);

        return match ($baseName) {
            'bool', 'char' => 1,
            'short' => 2,
            'int', 'float' => 4,
            'long', 'double' => 8,
            'long long' => 8,
            'void' => 0,
            default => 8,
        };
    }

    /**
     * Get the alignment requirement for a type.
     *
     * Types are aligned to their own size, except:
     * - struct alignment is max of all member alignments
     * - array alignment is the alignment of the element type
     */
    public function getTypeAlignment(TypeNode $type): int
    {
        // Pointers are always 8-aligned on x86-64
        if ($type->pointerDepth > 0 || $type->isReference) {
            return 8;
        }

        // Array alignment = element alignment
        if ($type->isArrayType()) {
            return $this->getTypeAlignment($type->getArrayElementType());
        }

        // Enum aligns like int
        if ($type->isEnum()) {
            return 4;
        }

        // Struct alignment from registered definition
        if ($type->isStruct()) {
            $key = $type->baseName;
            if (isset($this->structDefs[$key])) {
                return $this->structDefs[$key]->alignment;
            }
            return 1;
        }

        // Union alignment from registered definition
        if ($type->isUnion()) {
            $key = $type->baseName;
            if (isset($this->structDefs[$key])) {
                return $this->structDefs[$key]->alignment;
            }
            return 1;
        }

        // Scalar types: alignment == size
        $baseName = $this->resolveCompoundBaseName($type);

        return match ($baseName) {
            'bool', 'char' => 1,
            'short' => 2,
            'int', 'float' => 4,
            'long', 'double' => 8,
            'long long' => 8,
            'void' => 1,
            default => 8,
        };
    }

    /**
     * If the given type is an array type, decay it to a pointer to its element type.
     * Otherwise return the type unchanged.
     */
    public function decayArrayToPointer(TypeNode $type): TypeNode
    {
        if (!$type->isArrayType()) {
            return $type;
        }

        $elementType = clone $type->getArrayElementType();
        $elementType->pointerDepth++;
        return $elementType;
    }

    /**
     * Compute the struct layout: assign offsets to members with proper alignment padding.
     * Updates the StructSymbol's member offsets, size, and alignment fields.
     */
    public function computeStructLayout(StructSymbol $sym): void
    {
        if ($sym->isUnion) {
            $this->computeUnionLayout($sym);
            return;
        }

        $offset = 0;
        $maxAlignment = 1;
        $updatedMembers = [];

        foreach ($sym->members as $name => $member) {
            $memberAlign = $this->getTypeAlignment($member['type']);
            $memberSize = $this->getTypeSize($member['type']);

            if ($memberAlign > $maxAlignment) {
                $maxAlignment = $memberAlign;
            }

            // Align offset to member alignment
            $offset = $this->alignTo($offset, $memberAlign);

            $updatedMembers[$name] = [
                'type' => $member['type'],
                'offset' => $offset,
                'bitWidth' => $member['bitWidth'],
            ];

            $offset += $memberSize;
        }

        // Final struct size is padded to struct alignment
        $offset = $this->alignTo($offset, $maxAlignment);

        $sym->members = $updatedMembers;
        $sym->size = $offset;
        $sym->alignment = $maxAlignment;
    }

    /**
     * Compute union layout: all members start at offset 0.
     * Size is the max of all member sizes, padded to alignment.
     */
    private function computeUnionLayout(StructSymbol $sym): void
    {
        $maxSize = 0;
        $maxAlignment = 1;
        $updatedMembers = [];

        foreach ($sym->members as $name => $member) {
            $memberAlign = $this->getTypeAlignment($member['type']);
            $memberSize = $this->getTypeSize($member['type']);

            if ($memberAlign > $maxAlignment) {
                $maxAlignment = $memberAlign;
            }
            if ($memberSize > $maxSize) {
                $maxSize = $memberSize;
            }

            $updatedMembers[$name] = [
                'type' => $member['type'],
                'offset' => 0,
                'bitWidth' => $member['bitWidth'],
            ];
        }

        // Union size is padded to alignment
        $maxSize = $this->alignTo($maxSize, $maxAlignment);

        $sym->members = $updatedMembers;
        $sym->size = $maxSize;
        $sym->alignment = $maxAlignment;
    }

    /**
     * Round up a value to the next multiple of alignment.
     */
    private function alignTo(int $value, int $alignment): int
    {
        if ($alignment <= 1) {
            return $value;
        }
        return (int) (ceil($value / $alignment) * $alignment);
    }

    /**
     * Resolve compound type specifiers into a canonical base name.
     * Handles "unsigned int", "signed char", "long long", etc.
     */
    private function resolveCompoundBaseName(TypeNode $type): string
    {
        $base = $type->baseName;

        // Already a compound specifier string
        if (in_array($base, [
            'unsigned int', 'signed int', 'unsigned char', 'signed char',
            'short int', 'long int', 'long long', 'long long int',
            'unsigned long', 'unsigned long long', 'unsigned short',
            'signed long', 'signed short',
        ], true)) {
            // Map compound specifiers to their canonical sizes
            return match ($base) {
                'unsigned int', 'signed int' => 'int',
                'unsigned char', 'signed char' => 'char',
                'short int', 'unsigned short', 'signed short' => 'short',
                'long int', 'unsigned long', 'signed long' => 'long',
                'long long', 'long long int', 'unsigned long long' => 'long long',
                default => $base,
            };
        }

        // Handle flag-based compound types
        if ($type->isShort) {
            return 'short';
        }
        if ($type->isLong && $base === 'long') {
            return 'long long'; // long long
        }
        if ($type->isLong) {
            return 'long';
        }

        return $base;
    }
}
