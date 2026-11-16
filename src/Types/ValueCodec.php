<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\UnsupportedTypeException;
use DuckDB\Quack\Transport\BinaryReader;

/**
 * Decodes a DuckDB `Value` from the storage wire format.
 *
 * DuckDB serializes values with `Value::Serialize`, which is a different
 * format from the vector data in a data chunk: integers use LEB128 and the
 * logical type travels with the value. Nested children borrow their type from
 * the parent, so no type field is written for them.
 */
final class ValueCodec
{
    /** `GeometryStorageType::WKB` in DuckDB's storage format. */
    private const GEOMETRY_FORMAT_WKB = 1;

    public static function decode(BinaryReader $reader, ?LogicalType $contextType = null): SerializedValue
    {
        return $reader->readObject(static function (BinaryReader $object) use ($contextType): SerializedValue {
            $logicalType = $object->peekFieldId() === 100
                ? $object->readRequiredField(100, static fn (BinaryReader $r) => LogicalTypeCodec::decodeLogicalType($r))
                : $contextType;
            if ($logicalType === null) {
                throw new ProtocolException('serialized DuckDB value is missing its logical type');
            }

            $isNull = $object->readRequiredField(101, static fn (BinaryReader $r) => $r->readBool());
            if ($isNull) {
                return SerializedValue::null($logicalType);
            }

            return new SerializedValue(
                $logicalType,
                false,
                self::decodeBody($object, $logicalType),
            );
        });
    }

    private static function decodeBody(BinaryReader $object, LogicalType $logicalType): mixed
    {
        if ($logicalType->id === LogicalTypeId::Type) {
            return $object->readRequiredField(
                102,
                static fn (BinaryReader $r) => LogicalTypeCodec::decodeLogicalType($r),
            );
        }

        $physicalType = LogicalTypeCodec::getPhysicalType($logicalType);
        return match ($physicalType) {
            PhysicalType::Bool => $object->readRequiredField(102, static fn (BinaryReader $r) => $r->readBool()),
            PhysicalType::Int8, PhysicalType::Int16, PhysicalType::Int32, PhysicalType::Int64 => $object->readRequiredField(
                102,
                static fn (BinaryReader $r) => $r->readSlebI64(),
            ),
            PhysicalType::UInt8, PhysicalType::UInt16, PhysicalType::UInt32, PhysicalType::UInt64 => $object->readRequiredField(
                102,
                static fn (BinaryReader $r) => $r->readUlebU64(),
            ),
            PhysicalType::Int128 => $object->readRequiredField(
                102,
                static fn (BinaryReader $r) => self::readSigned128($r),
            ),
            PhysicalType::UInt128 => $object->readRequiredField(
                102,
                static fn (BinaryReader $r) => self::readUnsigned128($r),
            ),
            PhysicalType::Float => $object->readRequiredField(102, static fn (BinaryReader $r) => $r->readFixedF32()),
            PhysicalType::Double => $object->readRequiredField(102, static fn (BinaryReader $r) => $r->readFixedF64()),
            PhysicalType::Interval => $object->readRequiredField(102, static fn (BinaryReader $r) => self::decodeInterval($r)),
            PhysicalType::Varchar => self::decodeVarchar($object, $logicalType),
            PhysicalType::List, PhysicalType::Array => $object->readRequiredField(102, static fn (BinaryReader $r) => self::decodeListValue($r, $logicalType)),
            PhysicalType::Struct => $object->readRequiredField(102, static fn (BinaryReader $r) => self::decodeStructValue($r, $logicalType)),
            default => throw new UnsupportedTypeException("serialized DuckDB value of logical type {$logicalType->id->name} (physical type {$physicalType->name}) is not supported"),
        };
    }

    private static function decodeVarchar(BinaryReader $object, LogicalType $logicalType): string
    {
        $raw = $object->readRequiredField(102, static fn (BinaryReader $r) => $r->readStringBytes());
        if ($logicalType->id === LogicalTypeId::Geometry) {
            // Newer storage versions write a geometry format marker after the value.
            // Older versions omit it and store the legacy SPATIAL format, which has
            // no PHP formatter, so those bytes are returned unchanged.
            $format = (int) $object->readOptionalField(
                103,
                static fn (BinaryReader $r) => $r->readUlebU64(),
                0,
            );

            return $format === self::GEOMETRY_FORMAT_WKB ? WktFormatter::fromWkb($raw) : $raw;
        }
        if ($logicalType->id === LogicalTypeId::Blob) {
            // BLOB values arrive in DuckDB's escaped `\xHH` representation.
            return stripcslashes($raw);
        }
        if ($logicalType->id === LogicalTypeId::Bit) {
            return BitString::fromStorage($raw);
        }
        if ($logicalType->id === LogicalTypeId::BigNum) {
            return Bignum::fromStorage($raw);
        }

        return $raw;
    }

    private static function decodeInterval(BinaryReader $reader): IntervalValue
    {
        return $reader->readObject(
            static fn (BinaryReader $object) => new IntervalValue(
                $object->readOptionalField(1, static fn (BinaryReader $r) => $r->readSlebI64(), 0),
                $object->readOptionalField(2, static fn (BinaryReader $r) => $r->readSlebI64(), 0),
                $object->readOptionalField(3, static fn (BinaryReader $r) => $r->readSlebI64(), 0),
            ),
        );
    }

    /**
     * @return list<SerializedValue>
     */
    private static function decodeListValue(BinaryReader $reader, LogicalType $logicalType): array
    {
        $childType = LogicalTypeCodec::getChildType($logicalType);
        return $reader->readObject(
            static fn (BinaryReader $object) => self::decodeListChildren($object, $childType),
        );
    }

    /**
     * @return list<SerializedValue>
     */
    private static function decodeListChildren(BinaryReader $reader, LogicalType $childType): array
    {
        return $reader->readRequiredField(
            100,
            static fn (BinaryReader $r) => $r->readList(
                static fn (BinaryReader $element) => self::decode($element, $childType),
            ),
        );
    }

    /**
     * @return array<string,SerializedValue>
     */
    private static function decodeStructValue(BinaryReader $reader, LogicalType $logicalType): array
    {
        $children = LogicalTypeCodec::getStructChildren($logicalType);
        $values = $reader->readObject(
            static fn (BinaryReader $object) => $object->readRequiredField(
                100,
                static fn (BinaryReader $r) => $r->readList(
                    static function (BinaryReader $element, int $index) use ($children): SerializedValue {
                        $child = $children[$index] ?? null;
                        if ($child === null) {
                            throw new ProtocolException("serialized DuckDB struct value child {$index} has no matching type");
                        }
                        return self::decode($element, $child->logicalType);
                    },
                ),
            ),
        );

        $result = [];
        foreach ($children as $index => $child) {
            $result[$child->name] = $values[$index] ?? SerializedValue::null($child->logicalType);
        }

        return $result;
    }

    private static function readSigned128(BinaryReader $reader): int|string
    {
        $upper = $reader->readSlebI64();
        $lower = $reader->readUlebU64();
        return BigInt::fromInt($upper)->shiftLeft(64)->add(BigInt::coerce($lower))->toIntOrString();
    }

    private static function readUnsigned128(BinaryReader $reader): int|string
    {
        $upper = $reader->readUlebU64();
        $lower = $reader->readUlebU64();
        return BigInt::coerce($upper)->shiftLeft(64)->add(BigInt::coerce($lower))->toIntOrString();
    }
}
