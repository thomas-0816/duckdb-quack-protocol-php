<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Types\Info\{
    ArrayTypeInfo,
    DecimalTypeInfo,
    EnumTypeInfo,
    GeoTypeInfo,
    ListTypeInfo,
    StructTypeInfo,
};

/**
 * Factories for the logical types the protocol can describe.
 */
final class LogicalTypes
{
    public static function null(): LogicalType
    {
        return new LogicalType(LogicalTypeId::SqlNull);
    }

    public static function boolean(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Boolean);
    }

    public static function tinyint(): LogicalType
    {
        return new LogicalType(LogicalTypeId::TinyInt);
    }

    public static function smallint(): LogicalType
    {
        return new LogicalType(LogicalTypeId::SmallInt);
    }

    public static function integer(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Integer);
    }

    public static function bigint(): LogicalType
    {
        return new LogicalType(LogicalTypeId::BigInt);
    }

    public static function utinyint(): LogicalType
    {
        return new LogicalType(LogicalTypeId::UTinyInt);
    }

    public static function usmallint(): LogicalType
    {
        return new LogicalType(LogicalTypeId::USmallInt);
    }

    public static function uinteger(): LogicalType
    {
        return new LogicalType(LogicalTypeId::UInteger);
    }

    public static function ubigint(): LogicalType
    {
        return new LogicalType(LogicalTypeId::UBigInt);
    }

    public static function hugeint(): LogicalType
    {
        return new LogicalType(LogicalTypeId::HugeInt);
    }

    public static function uhugeint(): LogicalType
    {
        return new LogicalType(LogicalTypeId::UHugeInt);
    }

    public static function float(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Float);
    }

    public static function double(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Double);
    }

    public static function varchar(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Varchar);
    }

    public static function blob(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Blob);
    }

    public static function bit(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Bit);
    }

    public static function uuid(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Uuid);
    }

    public static function date(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Date);
    }

    public static function time(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Time);
    }

    public static function timeNs(): LogicalType
    {
        return new LogicalType(LogicalTypeId::TimeNs);
    }

    public static function timeTz(): LogicalType
    {
        return new LogicalType(LogicalTypeId::TimeTz);
    }

    public static function timestamp(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Timestamp);
    }

    public static function timestampSeconds(): LogicalType
    {
        return new LogicalType(LogicalTypeId::TimestampSec);
    }

    public static function timestampMillis(): LogicalType
    {
        return new LogicalType(LogicalTypeId::TimestampMs);
    }

    public static function timestampNanos(): LogicalType
    {
        return new LogicalType(LogicalTypeId::TimestampNs);
    }

    public static function timestampTz(): LogicalType
    {
        return new LogicalType(LogicalTypeId::TimestampTz);
    }

    public static function interval(): LogicalType
    {
        return new LogicalType(LogicalTypeId::Interval);
    }

    public static function decimal(int $width, int $scale): LogicalType
    {
        return new LogicalType(
            LogicalTypeId::Decimal,
            new DecimalTypeInfo(null, $width, $scale),
        );
    }

    public static function list(LogicalType $childType): LogicalType
    {
        return new LogicalType(
            LogicalTypeId::List,
            new ListTypeInfo(null, $childType),
        );
    }

    /**
     * MAP is encoded as LIST<STRUCT<key, value>>, matching DuckDB and the Rust
     * SDK.
     */
    public static function map(LogicalType $keyType, LogicalType $valueType): LogicalType
    {
        return self::list(self::struct([
            new ChildType('key', $keyType),
            new ChildType('value', $valueType),
        ]))->withId(LogicalTypeId::Map);
    }

    /**
     * @param list<ChildType> $childTypes
     */
    public static function struct(array $childTypes): LogicalType
    {
        return new LogicalType(
            LogicalTypeId::Struct,
            new StructTypeInfo(null, $childTypes),
        );
    }

    public static function array(LogicalType $childType, int $size): LogicalType
    {
        return new LogicalType(
            LogicalTypeId::Array,
            new ArrayTypeInfo(null, $childType, $size),
        );
    }

    /**
     * @param list<string> $values
     */
    public static function enum(array $values): LogicalType
    {
        return new LogicalType(
            LogicalTypeId::Enum,
            new EnumTypeInfo(null, $values),
        );
    }

    public static function geometry(?CoordinateReferenceSystem $crs = null): LogicalType
    {
        if ($crs === null) {
            return new LogicalType(LogicalTypeId::Geometry);
        }
        return new LogicalType(
            LogicalTypeId::Geometry,
            new GeoTypeInfo(null, $crs),
        );
    }

    /**
     * VARIANT and UNION use the struct physical layout. Their child metadata is
     * supplied by the server as a StructTypeInfo when it is available.
     */
    public static function variant(?StructTypeInfo $children = null): LogicalType
    {
        return new LogicalType(LogicalTypeId::Variant, $children);
    }

    public static function union(?StructTypeInfo $children = null): LogicalType
    {
        return new LogicalType(LogicalTypeId::Union, $children);
    }
}
