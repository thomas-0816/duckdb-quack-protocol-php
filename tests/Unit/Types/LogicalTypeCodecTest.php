<?php

namespace DuckDB\Quack\Tests\Unit\Types;

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
