<?php

namespace DuckDB\Quack\Tests\Unit\Chunk;

use DuckDB\Quack\Chunk\Builders;
use DuckDB\Quack\Chunk\DataChunkCodec;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Transport\BinaryWriter;
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
}
