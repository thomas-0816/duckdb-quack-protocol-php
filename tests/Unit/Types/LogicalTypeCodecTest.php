<?php

namespace DuckDB\Quack\Tests\Unit\Types;

use DuckDB\Quack\Exceptions\UnsupportedTypeException;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Transport\BinaryWriter;
use DuckDB\Quack\Types\ExtraTypeInfoType;
use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\LogicalTypeCodec;
use DuckDB\Quack\Types\LogicalTypeId;
use DuckDB\Quack\Types\SerializedValue;
use DuckDB\Quack\Types\ValueCodec;
use PHPUnit\Framework\TestCase;

final class LogicalTypeCodecTest extends TestCase
{
    public function testDecodesExtensionTypeInfo(): void
    {
        $type = self::decodeLogicalType(self::aggregateStateType());

        self::assertSame(LogicalTypeId::Struct, $type->id);
        self::assertSame('AGGREGATE_STATE', $type->alias());

        $children = LogicalTypeCodec::getStructChildren($type);
        self::assertCount(1, $children);
        self::assertSame('value', $children[0]->name);
        self::assertSame(LogicalTypeId::BigInt, $children[0]->logicalType->id);

        $extensionInfo = $type->typeInfo?->extensionInfo;
        self::assertNotNull($extensionInfo);

        self::assertCount(1, $extensionInfo->modifiers);
        self::assertSame('answer', $extensionInfo->modifiers[0]->label);
        self::assertSame(42, $extensionInfo->modifiers[0]->value->toPhp());

        self::assertSame('sum', $extensionInfo->properties['function_name']->toPhp());

        $parameters = $extensionInfo->properties['parameters']->toPhp();
        self::assertIsArray($parameters);
        self::assertCount(1, $parameters);
        self::assertInstanceOf(LogicalType::class, $parameters[0]);
        self::assertSame(LogicalTypeId::Integer, $parameters[0]->id);
    }

    private static function decodeLogicalType(BinaryWriter $writer): LogicalType
    {
        $reader = new BinaryReader($writer->intoBytes());
        $type = LogicalTypeCodec::decodeLogicalType($reader);
        $reader->assertEof();

        return $type;
    }

    public function testDecodesSerializedValues(): void
    {
        $signed = BinaryWriter::new();
        self::writeValue($signed, LogicalTypeId::Integer, static fn (BinaryWriter $w) => $w->writeSleb(-42));
        self::assertSame(-42, self::decodeValue($signed)->toPhp());

        $unsigned = BinaryWriter::new();
        self::writeValue(
            $unsigned,
            LogicalTypeId::UBigInt,
            static fn (BinaryWriter $w) => $w->writeUleb('18446744073709551615'),
        );
        self::assertSame('18446744073709551615', self::decodeValue($unsigned)->toPhp());

        $unsigned128 = BinaryWriter::new();
        self::writeValue(
            $unsigned128,
            LogicalTypeId::UHugeInt,
            static function (BinaryWriter $w): void {
                $w->writeUleb('18446744073709551615');
                $w->writeUleb('18446744073709551615');
            },
        );
        self::assertSame(
            '340282366920938463463374607431768211455',
            self::decodeValue($unsigned128)->toPhp(),
        );

        $blob = BinaryWriter::new();
        self::writeValue(
            $blob,
            LogicalTypeId::Blob,
            static fn (BinaryWriter $w) => $w->writeString('\x00\xff'),
        );
        self::assertSame("\x00\xff", self::decodeValue($blob)->toPhp());

        $null = BinaryWriter::new();
        self::writeNullValue($null, LogicalTypeId::Varchar);
        self::assertNull(self::decodeValue($null)->toPhp());
    }

    public function testDecodesBitBignumAndGeometryValues(): void
    {
        $bit = BinaryWriter::new();
        self::writeValue($bit, LogicalTypeId::Bit, static fn (BinaryWriter $w) => $w->writeStringBytes("\x05\xFD"));
        self::assertSame('101', self::decodeValue($bit)->toPhp());

        $bignum = BinaryWriter::new();
        self::writeValue(
            $bignum,
            LogicalTypeId::BigNum,
            static fn (BinaryWriter $w) => $w->writeStringBytes("\x80\x00\x01\x01"),
        );
        self::assertSame('1', self::decodeValue($bignum)->toPhp());

        $negativeBignum = BinaryWriter::new();
        self::writeValue(
            $negativeBignum,
            LogicalTypeId::BigNum,
            static fn (BinaryWriter $w) => $w->writeStringBytes("\x7F\xFF\xFE\xFE"),
        );
        self::assertSame('-1', self::decodeValue($negativeBignum)->toPhp());

        $geometry = BinaryWriter::new();
        $geometry->writeObject(static function (BinaryWriter $value): void {
            $value->writeField(100, static fn (BinaryWriter $w) => self::writeLogicalType($w, LogicalTypeId::Geometry));
            $value->writeField(101, static fn (BinaryWriter $w) => $w->writeBool(false));
            $value->writeField(
                102,
                static fn (BinaryWriter $w) => $w->writeStringBytes(
                    (string) hex2bin('01010000000000000000003E400000000000002440'),
                ),
            );
            $value->writeField(103, static fn (BinaryWriter $w) => $w->writeUleb(1));
        });
        self::assertSame('POINT (30 10)', self::decodeValue($geometry)->toPhp());
    }

    public function testDecodesEnumValueAsLabel(): void
    {
        $value = BinaryWriter::new();
        $value->writeObject(static function (BinaryWriter $object): void {
            $object->writeField(100, static fn (BinaryWriter $w) => self::writeEnumType($w, ['red', 'green']));
            $object->writeField(101, static fn (BinaryWriter $w) => $w->writeBool(false));
            $object->writeField(102, static fn (BinaryWriter $w) => $w->writeUleb(1));
        });

        self::assertSame('green', self::decodeValue($value)->toPhp());
    }

    public function testDecodesArrayValue(): void
    {
        $value = BinaryWriter::new();
        $value->writeObject(static function (BinaryWriter $object): void {
            $object->writeField(100, static fn (BinaryWriter $w) => self::writeArrayType($w, LogicalTypeId::Integer, 3));
            $object->writeField(101, static fn (BinaryWriter $w) => $w->writeBool(false));
            $object->writeField(102, static function (BinaryWriter $w): void {
                $w->writeObject(static function (BinaryWriter $children): void {
                    $children->writeField(100, static fn (BinaryWriter $list) => $list->writeList(
                        [1, 2, 3],
                        static fn (BinaryWriter $element, int $item) => $element->writeObject(
                            static function (BinaryWriter $child) use ($item): void {
                                $child->writeField(101, static fn (BinaryWriter $b) => $b->writeBool(false));
                                $child->writeField(102, static fn (BinaryWriter $b) => $b->writeSleb($item));
                            },
                        ),
                    ));
                });
            });
        });

        self::assertSame([1, 2, 3], self::decodeValue($value)->toPhp());
    }

    public function testRejectsUnsupportedLogicalTypeValues(): void
    {
        $value = BinaryWriter::new();
        self::writeValue($value, LogicalTypeId::Unknown, static fn (BinaryWriter $w) => $w->writeUleb(7));

        $this->expectException(UnsupportedTypeException::class);
        $this->expectExceptionMessage('logical type Unknown (physical type Invalid) is not supported');
        self::decodeValue($value);
    }

    private static function decodeValue(BinaryWriter $writer): SerializedValue
    {
        $reader = new BinaryReader($writer->intoBytes());
        $value = ValueCodec::decode($reader);
        $reader->assertEof();

        return $value;
    }

    /**
     * Builds a STRUCT type with alias AGGREGATE_STATE, one extension
     * modifier, and extension properties for function_name/parameters.
     */
    private static function aggregateStateType(): BinaryWriter
    {
        $writer = BinaryWriter::new();
        $writer->writeObject(static function (BinaryWriter $type): void {
            $type->writeField(100, static fn (BinaryWriter $w) => $w->writeUleb(LogicalTypeId::Struct->value));
            $type->writeField(
                101,
                static function (BinaryWriter $w): void {
                    $w->writeBool(true);
                    $w->writeObject(static function (BinaryWriter $info): void {
                        $info->writeField(
                            100,
                            static fn (BinaryWriter $f) => $f->writeUleb(ExtraTypeInfoType::Struct->value),
                        );
                        $info->writeField(101, static fn (BinaryWriter $f) => $f->writeString('AGGREGATE_STATE'));
                        $info->writeField(103, self::writeExtensionInfo(...));
                        $info->writeField(200, self::writeStructChildren(...));
                    });
                },
            );
        });

        return $writer;
    }

    private static function writeExtensionInfo(BinaryWriter $writer): void
    {
        $writer->writeBool(true);
        $writer->writeObject(static function (BinaryWriter $info): void {
            $info->writeField(
                100,
                static function (BinaryWriter $w): void {
                    $w->writeList(
                        [null],
                        static fn (BinaryWriter $list) => $list->writeObject(
                            static function (BinaryWriter $modifier): void {
                                $modifier->writeField(
                                    100,
                                    static fn (BinaryWriter $value) => self::writeValue(
                                        $value,
                                        LogicalTypeId::Integer,
                                        static fn (BinaryWriter $v) => $v->writeSleb(42),
                                    ),
                                );
                                $modifier->writeField(101, static fn (BinaryWriter $v) => $v->writeString('answer'));
                            },
                        ),
                    );
                },
            );
            $info->writeField(
                101,
                static function (BinaryWriter $w): void {
                    $w->writeList(
                        ['function_name', 'parameters'],
                        static function (BinaryWriter $list, string $name): void {
                            $list->writeObject(static function (BinaryWriter $entry) use ($name): void {
                                $entry->writeField(0, static fn (BinaryWriter $key) => $key->writeString($name));
                                $entry->writeField(1, static function (BinaryWriter $value) use ($name): void {
                                    if ($name === 'function_name') {
                                        self::writeValue(
                                            $value,
                                            LogicalTypeId::Varchar,
                                            static fn (BinaryWriter $v) => $v->writeString('sum'),
                                        );
                                    } else {
                                        self::writeTypeListValue($value, [LogicalTypeId::Integer]);
                                    }
                                });
                            });
                        },
                    );
                },
            );
        });
    }

    /**
     * @param list<string> $values
     */
    private static function writeEnumType(BinaryWriter $writer, array $values): void
    {
        $writer->writeObject(static function (BinaryWriter $type) use ($values): void {
            $type->writeField(100, static fn (BinaryWriter $w) => $w->writeUleb(LogicalTypeId::Enum->value));
            $type->writeField(101, static function (BinaryWriter $w) use ($values): void {
                $w->writeBool(true);
                $w->writeObject(static function (BinaryWriter $info) use ($values): void {
                    $info->writeField(
                        100,
                        static fn (BinaryWriter $w) => $w->writeUleb(ExtraTypeInfoType::Enum->value),
                    );
                    $info->writeField(200, static fn (BinaryWriter $w) => $w->writeUleb(count($values)));
                    $info->writeField(201, static fn (BinaryWriter $w) => $w->writeList(
                        $values,
                        static fn (BinaryWriter $list, string $value) => $list->writeString($value),
                    ));
                });
            });
        });
    }

    private static function writeArrayType(
        BinaryWriter $writer,
        LogicalTypeId $childType,
        int $size,
    ): void {
        $writer->writeObject(static function (BinaryWriter $type) use ($childType, $size): void {
            $type->writeField(100, static fn (BinaryWriter $w) => $w->writeUleb(LogicalTypeId::Array->value));
            $type->writeField(101, static function (BinaryWriter $w) use ($childType, $size): void {
                $w->writeBool(true);
                $w->writeObject(static function (BinaryWriter $info) use ($childType, $size): void {
                    $info->writeField(
                        100,
                        static fn (BinaryWriter $w) => $w->writeUleb(ExtraTypeInfoType::Array->value),
                    );
                    $info->writeField(200, static fn (BinaryWriter $w) => self::writeLogicalType($w, $childType));
                    $info->writeField(201, static fn (BinaryWriter $w) => $w->writeUleb($size));
                });
            });
        });
    }

    private static function writeStructChildren(BinaryWriter $writer): void
    {
        $writer->writeList(
            ['value'],
            static fn (BinaryWriter $list) => $list->writeObject(
                static function (BinaryWriter $child): void {
                    $child->writeField(0, static fn (BinaryWriter $w) => $w->writeString('value'));
                    $child->writeField(1, static fn (BinaryWriter $w) => self::writeLogicalType($w, LogicalTypeId::BigInt));
                },
            ),
        );
    }

    /**
     * Writes a LIST(TYPE) value whose children omit their serialized type and
     * rely on the parent's child type instead.
     *
     * @param list<LogicalTypeId> $types
     */
    private static function writeTypeListValue(BinaryWriter $writer, array $types): void
    {
        $writer->writeObject(static function (BinaryWriter $value) use ($types): void {
            $value->writeField(100, static function (BinaryWriter $logicalType): void {
                $logicalType->writeObject(static function (BinaryWriter $list): void {
                    $list->writeField(
                        100,
                        static fn (BinaryWriter $w) => $w->writeUleb(LogicalTypeId::List->value),
                    );
                    $list->writeField(101, static function (BinaryWriter $info): void {
                        $info->writeBool(true);
                        $info->writeObject(static function (BinaryWriter $listInfo): void {
                            $listInfo->writeField(
                                100,
                                static fn (BinaryWriter $w) => $w->writeUleb(ExtraTypeInfoType::List->value),
                            );
                            $listInfo->writeField(
                                200,
                                static fn (BinaryWriter $w) => self::writeLogicalType($w, LogicalTypeId::Type),
                            );
                        });
                    });
                });
            });
            $value->writeField(101, static fn (BinaryWriter $w) => $w->writeBool(false));
            $value->writeField(
                102,
                static function (BinaryWriter $children) use ($types): void {
                    $children->writeObject(static function (BinaryWriter $object) use ($types): void {
                        $object->writeField(
                            100,
                            static fn (BinaryWriter $w) => $w->writeList(
                                $types,
                                static fn (BinaryWriter $list, LogicalTypeId $type) => self::writeTypeValue(
                                    $list,
                                    $type,
                                ),
                            ),
                        );
                    });
                },
            );
        });
    }

    private static function writeTypeValue(BinaryWriter $writer, LogicalTypeId $type): void
    {
        $writer->writeObject(static function (BinaryWriter $value) use ($type): void {
            $value->writeField(101, static fn (BinaryWriter $w) => $w->writeBool(false));
            $value->writeField(
                102,
                static fn (BinaryWriter $w) => self::writeLogicalType($w, $type),
            );
        });
    }

    /**
     * @param callable(BinaryWriter):void $writeRawValue
     */
    private static function writeValue(BinaryWriter $writer, LogicalTypeId $type, callable $writeRawValue): void
    {
        $writer->writeObject(static function (BinaryWriter $value) use ($type, $writeRawValue): void {
            $value->writeField(100, static fn (BinaryWriter $w) => self::writeLogicalType($w, $type));
            $value->writeField(101, static fn (BinaryWriter $w) => $w->writeBool(false));
            $value->writeField(102, $writeRawValue);
        });
    }

    private static function writeNullValue(BinaryWriter $writer, LogicalTypeId $type): void
    {
        $writer->writeObject(static function (BinaryWriter $value) use ($type): void {
            $value->writeField(100, static fn (BinaryWriter $w) => self::writeLogicalType($w, $type));
            $value->writeField(101, static fn (BinaryWriter $w) => $w->writeBool(true));
        });
    }

    private static function writeLogicalType(BinaryWriter $writer, LogicalTypeId $type): void
    {
        $writer->writeObject(static function (BinaryWriter $logicalType) use ($type): void {
            $logicalType->writeField(100, static fn (BinaryWriter $w) => $w->writeUleb($type->value));
        });
    }
}
