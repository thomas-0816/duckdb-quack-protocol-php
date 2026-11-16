<?php

namespace DuckDB\Quack\Tests\Unit\Chunk;

use DuckDB\Quack\Chunk\Builders;
use DuckDB\Quack\Chunk\DataChunkCodec;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Transport\BinaryWriter;
use DuckDB\Quack\Types\ChildType;
use DuckDB\Quack\Types\Info\StructTypeInfo;
use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\LogicalTypeCodec;
use DuckDB\Quack\Types\LogicalTypeId;
use DuckDB\Quack\Types\LogicalTypes;
use DuckDB\Quack\Types\Value;
use DuckDB\Quack\Types\VectorType;
use PHPUnit\Framework\TestCase;

final class DataChunkCodecTest extends TestCase
{
    public function testDataChunkWrapperRoundTrips(): void
    {
        $chunk = Builders::dataChunk([
            Builders::column(LogicalTypes::integer(), [Value::int(1), Value::null()], 'id'),
            Builders::column(LogicalTypes::varchar(), [Value::string('a'), Value::string('bc')], 'label'),
            Builders::column(
                LogicalTypes::list(LogicalTypes::integer()),
                [
                    Value::list([Value::int(1), Value::int(2)]),
                    Value::list([]),
                ],
                'items',
            ),
        ]);

        $writer = BinaryWriter::new();
        DataChunkCodec::encodeDataChunkWrapper($writer, $chunk);
        $reader = new BinaryReader($writer->intoBytes());
        $decoded = DataChunkCodec::decodeDataChunkWrapperToPhp($reader, []);
        $reader->assertEof();

        self::assertSame(2, $decoded->rowCount);
        self::assertSame(
            [1, null],
            $decoded->columns[0]->values,
        );
        self::assertSame(
            ['a', 'bc'],
            $decoded->columns[1]->values,
        );
        self::assertSame(
            [
                [1, 2],
                [],
            ],
            $decoded->columns[2]->values,
        );
    }

    public function testRawDataChunkRoundTrips(): void
    {
        $chunk = Builders::dataChunk([
            Builders::column(
                LogicalTypes::map(LogicalTypes::varchar(), LogicalTypes::integer()),
                [
                    Value::list([
                        Value::struct([
                            'key' => Value::string('a'),
                            'value' => Value::int(1),
                        ]),
                    ]),
                ],
                'mapping',
            ),
        ]);

        $writer = BinaryWriter::new();
        DataChunkCodec::encodeDataChunk($writer, $chunk);
        $reader = new BinaryReader($writer->intoBytes());
        $decoded = DataChunkCodec::decodeDataChunkToPhp($reader, []);
        $reader->assertEof();

        self::assertSame(1, $decoded->rowCount);
        self::assertEquals(
            LogicalTypes::map(LogicalTypes::varchar(), LogicalTypes::integer()),
            $decoded->types[0],
        );
        self::assertSame(
            [
                ['a' => 1],
            ],
            $decoded->columns[0]->values,
        );
    }

    public function testStringVectorsUseTheV3Layout(): void
    {
        $chunk = Builders::dataChunk([
            Builders::column(
                LogicalTypes::varchar(),
                [Value::string('a'), Value::null(), Value::string('bc')],
                'label',
            ),
        ]);

        $writer = BinaryWriter::new();
        DataChunkCodec::encodeDataChunk($writer, $chunk);
        $reader = new BinaryReader($writer->intoBytes());

        $columns = $reader->readObject(
            static fn (BinaryReader $chunkReader) => self::readChunkColumns(
                $chunkReader,
                self::readV3StringVector(...),
            ),
        );
        $reader->assertEof();

        [$byteLength, $lengths, $bytes] = $columns[0];
        self::assertSame(3, $byteLength);
        self::assertSame(pack('V', 1) . pack('V', 0) . pack('V', 2), $lengths);
        self::assertSame('abc', $bytes);
    }

    public function testGeometryVectorsKeepTheWkbListLayout(): void
    {
        $wkb = "\x01\x02\x00\x00\x00";
        $chunk = Builders::dataChunk([
            Builders::column(LogicalTypes::geometry(), [Value::bytes($wkb)], 'geom'),
        ]);

        $writer = BinaryWriter::new();
        DataChunkCodec::encodeDataChunk($writer, $chunk);
        $reader = new BinaryReader($writer->intoBytes());

        $columns = $reader->readObject(
            static fn (BinaryReader $chunkReader) => self::readChunkColumns(
                $chunkReader,
                self::readGeometryVector(...),
            ),
        );
        $reader->assertEof();

        self::assertSame([$wkb], $columns[0]);
    }

    /**
     * @template T
     * @param callable(BinaryReader):T $readColumn
     * @return list<T>
     */
    private static function readChunkColumns(BinaryReader $chunkReader, callable $readColumn): array
    {
        $chunkReader->readRequiredField(
            100,
            static fn (BinaryReader $r) => $r->readUlebU64(),
        );
        $chunkReader->readRequiredField(101, static fn (BinaryReader $r) => $r->readList(
            static fn (BinaryReader $rr) => LogicalTypeCodec::decodeLogicalType($rr),
        ));

        return $chunkReader->readRequiredField(102, static fn (BinaryReader $r) => $r->readList(
            static fn (BinaryReader $rr) => $r->readObject(
                static fn (BinaryReader $vector) => $readColumn($vector),
            ),
        ));
    }

    /**
     * @return array{int|string, string, string}
     */
    private static function readV3StringVector(BinaryReader $vector): array
    {
        $vector->readRequiredField(100, static fn (BinaryReader $r) => $r->readBool());
        $vector->readOptionalField(101, static fn (BinaryReader $r) => $r->readBlob(), null);

        return [
            $vector->readRequiredField(107, static fn (BinaryReader $r) => $r->readUlebU64()),
            $vector->readRequiredField(108, static fn (BinaryReader $r) => $r->readBlob()),
            $vector->readRequiredField(109, static fn (BinaryReader $r) => $r->readBlob()),
        ];
    }

    /**
     * @return list<string>
     */
    private static function readGeometryVector(BinaryReader $vector): array
    {
        $vector->readRequiredField(99, static fn (BinaryReader $r) => $r->readUlebU64());
        $vector->readRequiredField(100, static fn (BinaryReader $r) => $r->readBool());
        $vector->readOptionalField(101, static fn (BinaryReader $r) => $r->readBlob(), null);
        self::assertSame(102, $vector->peekFieldId());

        return $vector->readRequiredField(102, static fn (BinaryReader $r) => $r->readList(
            static fn (BinaryReader $rr) => $rr->readStringBytes(),
        ));
    }

    public function testDecodesTimestampTzNanosToUtcString(): void
    {
        $chunk = Builders::dataChunk([
            Builders::column(
                new LogicalType(LogicalTypeId::TimestampTzNs),
                [Value::int(1_123_456_789)],
                'v',
            ),
        ]);

        $writer = BinaryWriter::new();
        DataChunkCodec::encodeDataChunk($writer, $chunk);
        $reader = new BinaryReader($writer->intoBytes());
        $decoded = DataChunkCodec::decodeDataChunkToPhp($reader, []);
        $reader->assertEof();

        self::assertSame(
            '1970-01-01 00:00:01.123456789+00',
            $decoded->columns[0]->values[0],
        );
    }

    public function testDecodesSequenceVectors(): void
    {
        $reader = self::sequenceVector(10, -3);
        $decoded = DataChunkCodec::decodeVector($reader, LogicalTypes::bigint(), 4);
        $reader->assertEof();
        self::assertSame([10, 7, 4, 1], $decoded->values);

        $reader = self::sequenceVector(1, 2);
        $decoded = DataChunkCodec::decodeVector($reader, LogicalTypes::date(), 3);
        $reader->assertEof();
        self::assertSame(
            ['1970-01-02', '1970-01-04', '1970-01-06'],
            $decoded->values,
        );
    }

    private static function sequenceVector(int $start, int $increment): BinaryReader
    {
        $writer = BinaryWriter::new();
        $writer->writeObject(static function (BinaryWriter $writer) use ($start, $increment): void {
            $writer->writeField(
                90,
                static fn (BinaryWriter $w) => $w->writeUleb(VectorType::Sequence->value),
            );
            $writer->writeField(91, static fn (BinaryWriter $w) => $w->writeSleb($start));
            $writer->writeField(92, static fn (BinaryWriter $w) => $w->writeSleb($increment));
        });

        return new BinaryReader($writer->intoBytes());
    }

    public function testDataChunkDecodesDirectlyToPhpWithProvidedTypes(): void
    {
        $types = [
            LogicalTypes::integer(),
            LogicalTypes::varchar(),
            LogicalTypes::list(LogicalTypes::integer()),
        ];
        $chunk = Builders::dataChunk([
            Builders::column($types[0], [Value::int(1), Value::null()], 'id'),
            Builders::column($types[1], [Value::string('a'), Value::string('bc')], 'label'),
            Builders::column(
                $types[2],
                [
                    Value::list([Value::int(1), Value::int(2)]),
                    Value::list([]),
                ],
                'items',
            ),
        ]);

        $writer = BinaryWriter::new();
        DataChunkCodec::encodeDataChunkWrapper($writer, $chunk);
        $reader = new BinaryReader($writer->intoBytes());
        $decoded = DataChunkCodec::decodeDataChunkWrapperToPhp($reader, $types, ['id', 'label', 'items']);
        $reader->assertEof();

        self::assertSame(2, $decoded->rowCount);
        self::assertSame([1, null], $decoded->columns[0]->values);
        self::assertSame(['a', 'bc'], $decoded->columns[1]->values);
        self::assertSame([[1, 2], []], $decoded->columns[2]->values);
        self::assertSame($types, $decoded->types);
        self::assertSame(['id', 'label', 'items'], $decoded->resultNames);
    }

    public function testDecodesFlattenedShreddedVariant(): void
    {
        $reader = self::shreddedVector(
            self::shreddedStructType(LogicalTypes::integer()),
            Value::null(),
            Value::int(42),
        );
        $decoded = DataChunkCodec::decodeVector($reader, self::variantType(), 1);
        $reader->assertEof();

        self::assertSame(VectorType::Shredded, $decoded->vectorType);
        self::assertSame([42], $decoded->values);
    }

    public function testDecodesShreddedVariantFallbackToUnshreddedValue(): void
    {
        $reader = self::shreddedVector(
            self::shreddedStructType(LogicalTypes::struct([
                new ChildType('typed_value', LogicalTypes::integer()),
                new ChildType('untyped_value_index', LogicalTypes::uinteger()),
            ])),
            self::canonicalVariantInt32(7),
            Value::struct([
                'typed_value' => Value::null(),
                'untyped_value_index' => Value::uint(1),
            ]),
        );
        $decoded = DataChunkCodec::decodeVector($reader, self::variantType(), 1);
        $reader->assertEof();

        self::assertSame([7], $decoded->values);
    }

    public function testDecodesShreddedVariantObjectOverlay(): void
    {
        $reader = self::shreddedVector(
            self::shreddedStructType(LogicalTypes::struct([
                new ChildType('typed_value', LogicalTypes::struct([
                    new ChildType('a', LogicalTypes::integer()),
                ])),
                new ChildType('untyped_value_index', LogicalTypes::uinteger()),
            ])),
            self::canonicalVariantObjectString('b', 6),
            Value::struct([
                'typed_value' => Value::struct(['a' => Value::int(5)]),
                'untyped_value_index' => Value::uint(1),
            ]),
        );
        $decoded = DataChunkCodec::decodeVector($reader, self::variantType(), 1);
        $reader->assertEof();

        self::assertSame([['a' => 5, 'b' => 6]], $decoded->values);
    }

    public function testDecodesNullShreddedVariant(): void
    {
        $reader = self::shreddedVector(
            self::shreddedStructType(LogicalTypes::integer()),
            Value::null(),
            Value::null(),
        );
        $decoded = DataChunkCodec::decodeVector($reader, self::variantType(), 1);
        $reader->assertEof();

        self::assertSame([null], $decoded->values);
    }

    private static function shreddedStructType(LogicalType $typedType): LogicalType
    {
        return LogicalTypes::struct([
            new ChildType('unshredded', self::variantType()),
            new ChildType('shredded', $typedType),
        ]);
    }

    private static function shreddedVector(LogicalType $outerType, Value $unshredded, Value $shredded): BinaryReader
    {
        $writer = BinaryWriter::new();
        $writer->writeObject(static function (BinaryWriter $object) use ($outerType, $unshredded, $shredded): void {
            $object->writeField(90, static fn (BinaryWriter $w) => $w->writeUleb(VectorType::Shredded->value));
            $object->writeField(91, static fn (BinaryWriter $w) => LogicalTypeCodec::encodeLogicalType($w, $outerType));
            $object->writeField(92, static function (BinaryWriter $w) use ($outerType, $unshredded, $shredded): void {
                DataChunkCodec::encodeVector($w, $outerType, [Value::struct(['unshredded' => $unshredded, 'shredded' => $shredded])], 1);
            });
        });

        return new BinaryReader($writer->intoBytes());
    }

    private static function variantType(): LogicalType
    {
        return LogicalTypes::variant(new StructTypeInfo(null, self::variantChildren()));
    }

    /**
     * @return list<ChildType>
     */
    private static function variantChildren(): array
    {
        return [
            new ChildType('keys', LogicalTypes::list(LogicalTypes::varchar())),
            new ChildType('children', LogicalTypes::list(LogicalTypes::struct([
                new ChildType('keys_index', LogicalTypes::uinteger()),
                new ChildType('values_index', LogicalTypes::uinteger()),
            ]))),
            new ChildType('values', LogicalTypes::list(LogicalTypes::struct([
                new ChildType('type_id', LogicalTypes::utinyint()),
                new ChildType('byte_offset', LogicalTypes::uinteger()),
            ]))),
            new ChildType('data', LogicalTypes::blob()),
        ];
    }

    private static function canonicalVariantInt32(int $value): Value
    {
        return self::canonicalVariant([], [], [['type_id' => 5, 'byte_offset' => 0]], pack('V', $value));
    }

    private static function canonicalVariantObjectString(string $key, int $value): Value
    {
        return self::canonicalVariant(
            [$key],
            [['keys_index' => 0, 'values_index' => 1]],
            [
                ['type_id' => 29, 'byte_offset' => 0],
                ['type_id' => 5, 'byte_offset' => 2],
            ],
            "\x01\x00" . pack('V', $value),
        );
    }

    /**
     * @param list<string>                                    $keys
     * @param list<array{keys_index:int,values_index:int}>    $children
     * @param list<array{type_id:int,byte_offset:int}>        $values
     */
    private static function canonicalVariant(array $keys, array $children, array $values, string $data): Value
    {
        return Value::struct([
            'keys' => Value::list(array_map(static fn (string $key) => Value::string($key), $keys)),
            'children' => Value::list(array_map(
                static fn (array $child) => Value::struct([
                    'keys_index' => Value::uint($child['keys_index']),
                    'values_index' => Value::uint($child['values_index']),
                ]),
                $children,
            )),
            'values' => Value::list(array_map(
                static fn (array $entry) => Value::struct([
                    'type_id' => Value::uint($entry['type_id']),
                    'byte_offset' => Value::uint($entry['byte_offset']),
                ]),
                $values,
            )),
            'data' => Value::bytes($data),
        ]);
    }
}
