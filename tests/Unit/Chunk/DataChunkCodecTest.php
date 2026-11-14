<?php

namespace DuckDB\Quack\Tests\Unit\Chunk;

use DuckDB\Quack\Chunk\Builders;
use DuckDB\Quack\Chunk\DataChunkCodec;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Transport\BinaryWriter;
use DuckDB\Quack\Types\LogicalTypes;
use DuckDB\Quack\Types\Value;
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
