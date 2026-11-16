<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;
use stdClass;

/**
 * Resolves DuckDB's shredded VARIANT representation into native PHP values.
 *
 * A shredded VARIANT splits each row into a canonical "unshredded" remainder
 * and a typed "shredded" tree. Every typed node is either a fully consistent
 * value or a STRUCT(typed_value, untyped_value_index) wrapper whose index
 * points into the unshredded value list (1-based; 0 means the value is absent).
 * OBJECT nodes merge their typed fields with the leftover fields of the
 * unshredded overlay object.
 */
final class ShreddedVariantDecoder
{
    private static ?stdClass $missing = null;

    private function __construct(private readonly ?VariantDecoder $unshredded)
    {
    }

    public static function decode(LogicalType $shreddedType, mixed $shreddedValue, ?VariantDecoder $unshredded): mixed
    {
        if ($shreddedValue === null) {
            // A NULL shredded component means the whole row is a SQL NULL.
            return null;
        }

        $result = (new self($unshredded))->resolveNode($shreddedType, $shreddedValue);

        return $result === self::missing() ? null : $result;
    }

    private function resolveNode(LogicalType $type, mixed $value): mixed
    {
        if ($type->id !== LogicalTypeId::Struct) {
            // A fully consistent primitive is stored directly.
            return $value;
        }
        if ($value === null) {
            return self::missing();
        }
        if (!is_array($value)) {
            throw new ProtocolException('shredded VARIANT node is not a struct');
        }

        $children = LogicalTypeCodec::getStructChildren($type);
        if ($children === [] || $children[0]->name !== 'typed_value') {
            // A typed OBJECT struct is only reached through a wrapper's
            // typed_value, but resolve it defensively as an object.
            return $this->resolveTyped($type, $value, 0);
        }

        $typedType = $children[0]->logicalType;
        $typedValue = $value[$children[0]->name] ?? null;
        $hasOverlay = count($children) > 1;

        if ($typedValue !== null) {
            $overlay = 0;
            if ($hasOverlay && $typedType->id === LogicalTypeId::Struct) {
                $untypedIndex = $value[$children[1]->name] ?? null;
                if ($untypedIndex !== null) {
                    $overlay = self::index($untypedIndex, 'untyped_value_index');
                }
            }
            return $this->resolveTyped($typedType, $typedValue, $overlay);
        }
        if (!$hasOverlay) {
            // No typed value and no overlay: this is a VARIANT NULL.
            return null;
        }
        $untypedIndex = $value[$children[1]->name] ?? null;
        if ($untypedIndex === null) {
            return null;
        }

        $index = self::index($untypedIndex, 'untyped_value_index');
        if ($index === 0) {
            return self::missing();
        }

        return $this->decodeUnshredded($index - 1);
    }

    private function resolveTyped(LogicalType $type, mixed $value, int $overlay): mixed
    {
        if ($type->id === LogicalTypeId::Struct) {
            if ($value === null) {
                return null;
            }
            if (!is_array($value)) {
                throw new ProtocolException('shredded VARIANT object is not a struct');
            }

            $result = [];
            foreach (LogicalTypeCodec::getStructChildren($type) as $child) {
                $resolved = $this->resolveNode($child->logicalType, $value[$child->name] ?? null);
                if ($resolved === self::missing()) {
                    continue;
                }
                $result[$child->name] = $resolved;
            }

            if ($overlay !== 0) {
                $overlayValue = $this->decodeUnshredded($overlay - 1);
                if (!is_array($overlayValue)) {
                    throw new ProtocolException('shredded VARIANT overlay is not an object');
                }
                // The overlay holds only the fields that were not shredded, so
                // appending them mirrors the server's object iteration order.
                foreach ($overlayValue as $key => $fieldValue) {
                    $result[$key] = $fieldValue;
                }
            }

            return $result;
        }

        if ($type->id === LogicalTypeId::List || $type->id === LogicalTypeId::Array) {
            if ($value === null) {
                return null;
            }
            if (!is_array($value) || !array_is_list($value)) {
                throw new ProtocolException('shredded VARIANT array is not a list');
            }
            $childType = LogicalTypeCodec::getChildType($type);
            $result = [];
            foreach ($value as $element) {
                $resolved = $this->resolveNode($childType, $element);
                $result[] = $resolved === self::missing() ? null : $resolved;
            }
            return $result;
        }

        return $value;
    }

    private function decodeUnshredded(int $index): mixed
    {
        if ($this->unshredded === null) {
            throw new ProtocolException("shredded VARIANT references unshredded value {$index}, but the unshredded component is NULL");
        }
        return $this->unshredded->decodeValue($index);
    }

    private static function index(mixed $value, string $name): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            $int = (int) $value;
            if ((string) $int === $value) {
                return $int;
            }
        }
        throw new ProtocolException("shredded VARIANT {$name} is not a valid index");
    }

    private static function missing(): stdClass
    {
        return self::$missing ??= new stdClass();
    }
}
