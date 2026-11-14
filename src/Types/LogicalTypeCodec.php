<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\UnsupportedTypeException;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Transport\BinaryWriter;
use DuckDB\Quack\Types\Info\{
    AggregateStateTypeInfo,
    AnyTypeInfo,
    ArrayTypeInfo,
    DecimalTypeInfo,
    EnumTypeInfo,
    ExtraTypeInfo,
    GenericTypeInfo,
    GeoTypeInfo,
    IntegerLiteralTypeInfo,
    InvalidTypeInfo,
    ListTypeInfo,
    StringTypeInfo,
    StructTypeInfo,
    TemplateTypeInfo,
    UnboundTypeInfo,
};

/**
 * Encodes and decodes DuckDB logical types, and answers the structural
 * questions the vector codec needs.
 */
final class LogicalTypeCodec
{
    public static function encodeLogicalType(BinaryWriter $writer, LogicalType $logicalType): void
    {
        $writer->writeObject(function (BinaryWriter $object) use ($logicalType): void {
            $object->writeField(100, fn (BinaryWriter $w) => $w->writeUleb($logicalType->id->value));
            if ($logicalType->typeInfo !== null) {
                $object->writeField(
                    101,
                    fn (BinaryWriter $w) => $w->writeNullable(
                        $logicalType->typeInfo,
                        self::encodeExtraTypeInfo(...),
                    ),
                );
            }
        });
    }

    public static function decodeLogicalType(BinaryReader $reader): LogicalType
    {
        return $reader->readObject(function (BinaryReader $object): LogicalType {
            $id = LogicalTypeId::fromId(
                $object->readRequiredField(100, static fn (BinaryReader $r) => $r->readUlebInt()),
            );
            $typeInfo = $object->readOptionalField(
                101,
                static fn (BinaryReader $r) => $r->readNullable(
                    self::decodeExtraTypeInfo(...),
                ),
                null,
            );
            return new LogicalType($id, $typeInfo);
        });
    }

    /**
     * Consumes a logical type from the chunk type list without building objects.
     *
     * @return int the top-level logical type id
     */
    public static function skipLogicalType(BinaryReader $reader): int
    {
        return $reader->readObject(static function (BinaryReader $object): int {
            $id = $object->readRequiredField(
                100,
                static fn (BinaryReader $r) => $r->readUlebInt(),
            );
            $object->readOptionalField(
                101,
                static fn (BinaryReader $r) => $r->readNullable(
                    self::skipExtraTypeInfo(...),
                ),
                null,
            );
            return $id;
        });
    }

    /**
     * @return list<int>
     */
    public static function skipLogicalTypeList(BinaryReader $reader): array
    {
        return $reader->readList(
            static fn (BinaryReader $r) => self::skipLogicalType($r),
        );
    }

    public static function encodeExtraTypeInfo(BinaryWriter $writer, ExtraTypeInfo $info): void
    {
        $writer->writeObject(function (BinaryWriter $object) use ($info): void {
            $object->writeField(100, fn (BinaryWriter $w) => $w->writeUleb($info->typeId()->value));
            if ($info->alias !== null && $info->alias !== '') {
                $object->writeField(101, fn (BinaryWriter $w) => $w->writeString($info->alias));
            }

            if ($info instanceof DecimalTypeInfo) {
                $object->writeField(200, fn (BinaryWriter $w) => $w->writeUleb($info->width));
                $object->writeField(201, fn (BinaryWriter $w) => $w->writeUleb($info->scale));
            } elseif ($info instanceof StringTypeInfo) {
                $object->writeField(200, fn (BinaryWriter $w) => $w->writeString($info->collation));
            } elseif ($info instanceof ListTypeInfo) {
                $object->writeField(
                    200,
                    fn (BinaryWriter $w) => self::encodeLogicalType($w, $info->childType),
                );
            } elseif ($info instanceof StructTypeInfo) {
                $object->writeField(
                    200,
                    fn (BinaryWriter $w) => self::encodeChildTypes($w, $info->childTypes),
                );
            } elseif ($info instanceof EnumTypeInfo) {
                $object->writeField(200, fn (BinaryWriter $w) => $w->writeUleb(count($info->values)));
                $object->writeField(
                    201,
                    fn (BinaryWriter $w) => $w->writeList(
                        $info->values,
                        static fn (BinaryWriter $ww, string $value) => $ww->writeString($value),
                    ),
                );
            } elseif ($info instanceof AggregateStateTypeInfo) {
                $object->writeField(
                    200,
                    fn (BinaryWriter $w) => $w->writeString($info->functionName),
                );
                $object->writeField(
                    201,
                    fn (BinaryWriter $w) => self::encodeLogicalType($w, $info->returnType),
                );
                $object->writeField(
                    202,
                    fn (BinaryWriter $w) => $w->writeList(
                        $info->boundArgumentTypes,
                        static fn (BinaryWriter $ww, LogicalType $type) => self::encodeLogicalType($ww, $type),
                    ),
                );
            } elseif ($info instanceof ArrayTypeInfo) {
                $object->writeField(
                    200,
                    fn (BinaryWriter $w) => self::encodeLogicalType($w, $info->childType),
                );
                $object->writeField(201, fn (BinaryWriter $w) => $w->writeUleb($info->size));
            } elseif ($info instanceof AnyTypeInfo) {
                $object->writeField(
                    200,
                    fn (BinaryWriter $w) => self::encodeLogicalType($w, $info->targetType),
                );
                $object->writeField(201, fn (BinaryWriter $w) => $w->writeUleb($info->castScore));
            } elseif ($info instanceof TemplateTypeInfo) {
                $object->writeField(200, fn (BinaryWriter $w) => $w->writeString($info->name));
            } elseif ($info instanceof IntegerLiteralTypeInfo) {
                throw new UnsupportedTypeException(
                    'encoding INTEGER_LITERAL type metadata is not supported',
                );
            } elseif ($info instanceof GeoTypeInfo) {
                $object->writeField(
                    200,
                    fn (BinaryWriter $w) => self::encodeCoordinateReferenceSystem($w, $info->crs),
                );
            } elseif ($info instanceof UnboundTypeInfo) {
                if ($info->name !== null) {
                    $object->writeField(200, fn (BinaryWriter $w) => $w->writeString($info->name));
                }
                if ($info->catalog !== null) {
                    $object->writeField(201, fn (BinaryWriter $w) => $w->writeString($info->catalog));
                }
                if ($info->schema !== null) {
                    $object->writeField(202, fn (BinaryWriter $w) => $w->writeString($info->schema));
                }
            } elseif (!$info instanceof InvalidTypeInfo && !$info instanceof GenericTypeInfo) {
                throw new UnsupportedTypeException(
                    'unknown extra type metadata ' . $info::class,
                );
            }
        });
    }

    public static function decodeExtraTypeInfo(BinaryReader $reader): ExtraTypeInfo
    {
        return $reader->readObject(function (BinaryReader $object): ExtraTypeInfo {
            $typeId = ExtraTypeInfoType::fromId(
                $object->readRequiredField(100, static fn (BinaryReader $r) => $r->readUlebInt()),
            );
            $alias = $object->readOptionalField(
                101,
                static fn (BinaryReader $r) => $r->readString(),
                null,
            );
            if ($object->peekFieldId() === 103) {
                throw new UnsupportedTypeException('extension type metadata is not supported');
            }

            return match ($typeId) {
                ExtraTypeInfoType::Invalid => new InvalidTypeInfo($alias),
                ExtraTypeInfoType::Generic => new GenericTypeInfo($alias),
                ExtraTypeInfoType::Decimal => new DecimalTypeInfo(
                    $alias,
                    $object->readOptionalField(200, static fn (BinaryReader $r) => $r->readUlebInt(), 0),
                    $object->readOptionalField(201, static fn (BinaryReader $r) => $r->readUlebInt(), 0),
                ),
                ExtraTypeInfoType::String => new StringTypeInfo(
                    $alias,
                    $object->readOptionalField(200, static fn (BinaryReader $r) => $r->readString(), ''),
                ),
                ExtraTypeInfoType::List => new ListTypeInfo(
                    $alias,
                    $object->readRequiredField(200, self::decodeLogicalType(...)),
                ),
                ExtraTypeInfoType::Struct => new StructTypeInfo(
                    $alias,
                    $object->readOptionalField(200, self::decodeChildTypes(...), []),
                ),
                ExtraTypeInfoType::Enum => self::decodeEnumTypeInfo($object, $alias),
                ExtraTypeInfoType::AggregateState => new AggregateStateTypeInfo(
                    $alias,
                    $object->readRequiredField(200, static fn (BinaryReader $r) => $r->readString()),
                    $object->readRequiredField(201, self::decodeLogicalType(...)),
                    $object->readRequiredField(
                        202,
                        static fn (BinaryReader $r) => $r->readList(
                            static fn (BinaryReader $rr) => self::decodeLogicalType($rr),
                        ),
                    ),
                ),
                ExtraTypeInfoType::Array => new ArrayTypeInfo(
                    $alias,
                    $object->readRequiredField(200, self::decodeLogicalType(...)),
                    $object->readRequiredField(201, static fn (BinaryReader $r) => $r->readUlebInt()),
                ),
                ExtraTypeInfoType::Any => new AnyTypeInfo(
                    $alias,
                    $object->readRequiredField(200, self::decodeLogicalType(...)),
                    $object->readRequiredField(201, static fn (BinaryReader $r) => $r->readUlebInt()),
                ),
                ExtraTypeInfoType::IntegerLiteral => self::decodeIntegerLiteral($object, $alias),
                ExtraTypeInfoType::Template => new TemplateTypeInfo(
                    $alias,
                    $object->readOptionalField(200, static fn (BinaryReader $r) => $r->readString(), ''),
                ),
                ExtraTypeInfoType::Geo => new GeoTypeInfo(
                    $alias,
                    $object->readOptionalField(
                        200,
                        self::decodeCoordinateReferenceSystem(...),
                        CoordinateReferenceSystem::default(),
                    ),
                ),
                ExtraTypeInfoType::Unbound => self::decodeUnbound($object, $alias),
            };
        });
    }

    /**
     * Mirrors decodeExtraTypeInfo() without allocating type metadata objects.
     */
    private static function skipExtraTypeInfo(BinaryReader $reader): void
    {
        $reader->readObject(static function (BinaryReader $object): void {
            $typeId = ExtraTypeInfoType::fromId(
                $object->readRequiredField(100, static fn (BinaryReader $r) => $r->readUlebInt()),
            );
            $object->readOptionalField(
                101,
                static fn (BinaryReader $r) => $r->readString(),
                null,
            );
            if ($object->peekFieldId() === 103) {
                throw new UnsupportedTypeException('extension type metadata is not supported');
            }

            switch ($typeId) {
                case ExtraTypeInfoType::Invalid:
                case ExtraTypeInfoType::Generic:
                    return;
                case ExtraTypeInfoType::Decimal:
                    $object->readOptionalField(
                        200,
                        static fn (BinaryReader $r) => $r->readUlebInt(),
                        0,
                    );
                    $object->readOptionalField(
                        201,
                        static fn (BinaryReader $r) => $r->readUlebInt(),
                        0,
                    );
                    return;
                case ExtraTypeInfoType::String:
                    $object->readOptionalField(
                        200,
                        static fn (BinaryReader $r) => $r->readString(),
                        '',
                    );
                    return;
                case ExtraTypeInfoType::List:
                    $object->readRequiredField(200, self::skipLogicalType(...));
                    return;
                case ExtraTypeInfoType::Struct:
                    $object->readOptionalField(200, self::skipChildTypes(...), []);
                    return;
                case ExtraTypeInfoType::Enum:
                    $count = $object->readRequiredField(
                        200,
                        static fn (BinaryReader $r) => $r->readUlebInt(),
                    );
                    $values = $object->readRequiredField(
                        201,
                        static fn (BinaryReader $r) => $r->readList(
                            static fn (BinaryReader $rr) => $rr->readString(),
                        ),
                    );
                    if (count($values) !== $count) {
                        throw new ProtocolException(
                            "ENUM metadata declared {$count} values but serialized "
                            . count($values),
                        );
                    }
                    return;
                case ExtraTypeInfoType::AggregateState:
                    $object->readRequiredField(
                        200,
                        static fn (BinaryReader $r) => $r->readString(),
                    );
                    $object->readRequiredField(201, self::skipLogicalType(...));
                    $object->readRequiredField(
                        202,
                        static fn (BinaryReader $r) => $r->readList(
                            static fn (BinaryReader $rr) => self::skipLogicalType($rr),
                        ),
                    );
                    return;
                case ExtraTypeInfoType::Array:
                case ExtraTypeInfoType::Any:
                    $object->readRequiredField(200, self::skipLogicalType(...));
                    $object->readRequiredField(
                        201,
                        static fn (BinaryReader $r) => $r->readUlebInt(),
                    );
                    return;
                case ExtraTypeInfoType::IntegerLiteral:
                    if ($object->peekFieldId() === 200) {
                        throw new UnsupportedTypeException(
                            'INTEGER_LITERAL type metadata contains a DuckDB Value, which is not supported',
                        );
                    }
                    return;
                case ExtraTypeInfoType::Template:
                    $object->readOptionalField(
                        200,
                        static fn (BinaryReader $r) => $r->readString(),
                        '',
                    );
                    return;
                case ExtraTypeInfoType::Geo:
                    $object->readOptionalField(
                        200,
                        static fn (BinaryReader $r) => $r->readObject(
                            static function (BinaryReader $crs): void {
                                $crs->readOptionalField(
                                    100,
                                    static fn (BinaryReader $rr) => $rr->readString(),
                                    null,
                                );
                            },
                        ),
                        null,
                    );
                    return;
                case ExtraTypeInfoType::Unbound:
                    if ($object->peekFieldId() === 204) {
                        throw new UnsupportedTypeException(
                            'UNBOUND type metadata contains a ParsedExpression, which is not supported',
                        );
                    }
                    $object->readOptionalField(
                        200,
                        static fn (BinaryReader $r) => $r->readString(),
                        null,
                    );
                    $object->readOptionalField(
                        201,
                        static fn (BinaryReader $r) => $r->readString(),
                        null,
                    );
                    $object->readOptionalField(
                        202,
                        static fn (BinaryReader $r) => $r->readString(),
                        null,
                    );
                    return;
            }
        });
    }

    /**
     * @return list<int>
     */
    private static function skipChildTypes(BinaryReader $reader): array
    {
        return $reader->readList(
            static fn (BinaryReader $r) => $r->readObject(
                static function (BinaryReader $pair): int {
                    $pair->readRequiredField(
                        0,
                        static fn (BinaryReader $rr) => $rr->readString(),
                    );
                    return $pair->readRequiredField(
                        1,
                        self::skipLogicalType(...),
                    );
                },
            ),
        );
    }

    public static function getPhysicalType(LogicalType $logicalType): PhysicalType
    {
        return match ($logicalType->id) {
            LogicalTypeId::Boolean => PhysicalType::Bool,
            LogicalTypeId::TinyInt => PhysicalType::Int8,
            LogicalTypeId::UTinyInt => PhysicalType::UInt8,
            LogicalTypeId::SmallInt => PhysicalType::Int16,
            LogicalTypeId::USmallInt => PhysicalType::UInt16,
            LogicalTypeId::SqlNull, LogicalTypeId::Date, LogicalTypeId::Integer => PhysicalType::Int32,
            LogicalTypeId::UInteger => PhysicalType::UInt32,
            LogicalTypeId::BigInt,
            LogicalTypeId::Time,
            LogicalTypeId::TimeNs,
            LogicalTypeId::Timestamp,
            LogicalTypeId::TimestampSec,
            LogicalTypeId::TimestampNs,
            LogicalTypeId::TimestampMs,
            LogicalTypeId::TimeTz,
            LogicalTypeId::TimestampTz => PhysicalType::Int64,
            LogicalTypeId::UBigInt => PhysicalType::UInt64,
            LogicalTypeId::UHugeInt => PhysicalType::UInt128,
            LogicalTypeId::HugeInt, LogicalTypeId::Uuid => PhysicalType::Int128,
            LogicalTypeId::Float => PhysicalType::Float,
            LogicalTypeId::Double => PhysicalType::Double,
            LogicalTypeId::Decimal => self::decimalPhysicalType($logicalType),
            LogicalTypeId::BigNum,
            LogicalTypeId::Varchar,
            LogicalTypeId::Char,
            LogicalTypeId::Blob,
            LogicalTypeId::Bit,
            LogicalTypeId::Type,
            LogicalTypeId::AggregateState,
            LogicalTypeId::Geometry => PhysicalType::Varchar,
            LogicalTypeId::Interval => PhysicalType::Interval,
            LogicalTypeId::Union, LogicalTypeId::Variant, LogicalTypeId::Struct => PhysicalType::Struct,
            LogicalTypeId::List, LogicalTypeId::Map => PhysicalType::List,
            LogicalTypeId::Array => PhysicalType::Array,
            LogicalTypeId::Pointer => PhysicalType::UInt64,
            LogicalTypeId::Validity => PhysicalType::Bit,
            LogicalTypeId::Enum => self::enumPhysicalType($logicalType),
            default => PhysicalType::Invalid,
        };
    }

    public static function isConstantSizePhysicalType(PhysicalType $physicalType): bool
    {
        return in_array($physicalType, [
            PhysicalType::Bool,
            PhysicalType::UInt8,
            PhysicalType::Int8,
            PhysicalType::UInt16,
            PhysicalType::Int16,
            PhysicalType::UInt32,
            PhysicalType::Int32,
            PhysicalType::UInt64,
            PhysicalType::Int64,
            PhysicalType::Float,
            PhysicalType::Double,
            PhysicalType::Interval,
            PhysicalType::UInt128,
            PhysicalType::Int128,
        ], true);
    }

    public static function physicalTypeSize(PhysicalType $physicalType): int
    {
        return match ($physicalType) {
            PhysicalType::Bool, PhysicalType::UInt8, PhysicalType::Int8 => 1,
            PhysicalType::UInt16, PhysicalType::Int16 => 2,
            PhysicalType::UInt32, PhysicalType::Int32, PhysicalType::Float => 4,
            PhysicalType::UInt64, PhysicalType::Int64, PhysicalType::Double => 8,
            PhysicalType::Interval, PhysicalType::UInt128, PhysicalType::Int128 => 16,
            default => throw new ProtocolException(
                "physical type {$physicalType->name} is not fixed size",
            ),
        };
    }

    public static function getChildType(LogicalType $logicalType): LogicalType
    {
        $info = $logicalType->typeInfo;
        if ($info instanceof ListTypeInfo || $info instanceof ArrayTypeInfo) {
            return $info->childType;
        }
        throw new ProtocolException(
            "logical type {$logicalType->id->name} does not have a child type",
        );
    }

    /**
     * @return list<ChildType>
     */
    public static function getStructChildren(LogicalType $logicalType): array
    {
        $info = $logicalType->typeInfo;
        if ($info instanceof StructTypeInfo) {
            return $info->childTypes;
        }
        if ($logicalType->id === LogicalTypeId::Variant || $logicalType->id === LogicalTypeId::Union) {
            throw new ProtocolException(
                "logical type {$logicalType->id->name} is missing StructTypeInfo; "
                . 'child field names cannot be decoded without it',
            );
        }
        throw new ProtocolException(
            "logical type {$logicalType->id->name} does not have struct children",
        );
    }

    public static function getArraySize(LogicalType $logicalType): int
    {
        $info = $logicalType->typeInfo;
        if ($info instanceof ArrayTypeInfo) {
            return $info->size;
        }
        throw new ProtocolException(
            "logical type {$logicalType->id->name} is not an ARRAY",
        );
    }

    /**
     * @return array{0:int,1:int}
     */
    public static function getDecimalWidthAndScale(LogicalType $logicalType): array
    {
        $info = $logicalType->typeInfo;
        if ($info instanceof DecimalTypeInfo) {
            return [$info->width, $info->scale];
        }
        throw new ProtocolException('DECIMAL type is missing DecimalTypeInfo');
    }

    /**
     * @return list<string>
     */
    public static function getEnumValues(LogicalType $logicalType): array
    {
        $info = $logicalType->typeInfo;
        if ($info instanceof EnumTypeInfo) {
            return $info->values;
        }
        throw new ProtocolException(
            "logical type {$logicalType->id->name} is not an ENUM",
        );
    }

    /**
     * @param list<ChildType> $children
     */
    private static function encodeChildTypes(BinaryWriter $writer, array $children): void
    {
        $writer->writeList($children, static function (BinaryWriter $w, ChildType $child): void {
            $w->writeObject(function (BinaryWriter $pair) use ($child): void {
                $pair->writeField(0, fn (BinaryWriter $ww) => $ww->writeString($child->name));
                $pair->writeField(
                    1,
                    fn (BinaryWriter $ww) => self::encodeLogicalType($ww, $child->logicalType),
                );
            });
        });
    }

    /**
     * @return list<ChildType>
     */
    private static function decodeChildTypes(BinaryReader $reader): array
    {
        return $reader->readList(
            static fn (BinaryReader $r) => $r->readObject(
                static fn (BinaryReader $pair) => new ChildType(
                    $pair->readRequiredField(0, static fn (BinaryReader $rr) => $rr->readString()),
                    $pair->readRequiredField(1, self::decodeLogicalType(...)),
                ),
            ),
        );
    }

    private static function encodeCoordinateReferenceSystem(
        BinaryWriter $writer,
        CoordinateReferenceSystem $crs,
    ): void {
        $writer->writeObject(function (BinaryWriter $object) use ($crs): void {
            if ($crs->definition !== null) {
                $object->writeField(100, fn (BinaryWriter $w) => $w->writeString($crs->definition));
            }
        });
    }

    private static function decodeCoordinateReferenceSystem(
        BinaryReader $reader,
    ): CoordinateReferenceSystem {
        return $reader->readObject(
            static fn (BinaryReader $object) => new CoordinateReferenceSystem(
                $object->readOptionalField(
                    100,
                    static fn (BinaryReader $r) => $r->readString(),
                    null,
                ),
            ),
        );
    }

    private static function decimalPhysicalType(LogicalType $logicalType): PhysicalType
    {
        [$width] = self::getDecimalWidthAndScale($logicalType);
        return match (true) {
            $width <= 4 => PhysicalType::Int16,
            $width <= 9 => PhysicalType::Int32,
            $width <= 18 => PhysicalType::Int64,
            $width <= 38 => PhysicalType::Int128,
            default => throw new ProtocolException("unsupported DECIMAL width {$width}"),
        };
    }

    private static function enumPhysicalType(LogicalType $logicalType): PhysicalType
    {
        $count = count(self::getEnumValues($logicalType));
        return match (true) {
            $count <= 0xff => PhysicalType::UInt8,
            $count <= 0xffff => PhysicalType::UInt16,
            default => PhysicalType::UInt32,
        };
    }

    private static function decodeEnumTypeInfo(BinaryReader $object, ?string $alias): EnumTypeInfo
    {
        $count = $object->readRequiredField(200, static fn (BinaryReader $r) => $r->readUlebInt());
        $values = $object->readRequiredField(
            201,
            static fn (BinaryReader $r) => $r->readList(
                static fn (BinaryReader $rr) => $rr->readString(),
            ),
        );
        if (count($values) !== $count) {
            throw new ProtocolException(
                "ENUM metadata declared {$count} values but serialized " . count($values),
            );
        }
        return new EnumTypeInfo($alias, $values);
    }

    private static function decodeIntegerLiteral(
        BinaryReader $object,
        ?string $alias,
    ): IntegerLiteralTypeInfo {
        if ($object->peekFieldId() === 200) {
            throw new UnsupportedTypeException(
                'INTEGER_LITERAL type metadata contains a DuckDB Value, which is not supported',
            );
        }
        return new IntegerLiteralTypeInfo($alias);
    }

    private static function decodeUnbound(BinaryReader $object, ?string $alias): UnboundTypeInfo
    {
        if ($object->peekFieldId() === 204) {
            throw new UnsupportedTypeException(
                'UNBOUND type metadata contains a ParsedExpression, which is not supported',
            );
        }
        return new UnboundTypeInfo(
            $alias,
            $object->readOptionalField(200, static fn (BinaryReader $r) => $r->readString(), null),
            $object->readOptionalField(201, static fn (BinaryReader $r) => $r->readString(), null),
            $object->readOptionalField(202, static fn (BinaryReader $r) => $r->readString(), null),
        );
    }
}
