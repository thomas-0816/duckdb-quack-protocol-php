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
        $decoded = DataChunkCodec::decodeDataChunkWrapper($reader);
        $reader->assertEof();

        self::assertSame(2, $decoded->rowCount);
        self::assertEquals(
            [
                ['id' => Value::int(1), 'label' => Value::string('a'), 'items' => Value::list([Value::int(1), Value::int(2)])],
                ['id' => Value::null(), 'label' => Value::string('bc'), 'items' => Value::list([])],
            ],
            DataChunkCodec::rowsFromChunkWithNames($decoded, ['id', 'label', 'items']),
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
        $decoded = DataChunkCodec::decodeDataChunk($reader);
        $reader->assertEof();

        self::assertSame(1, $decoded->rowCount);
        self::assertEquals(
            LogicalTypes::map(LogicalTypes::varchar(), LogicalTypes::integer()),
            $decoded->types[0],
        );
        self::assertEquals(
            [[
                'mapping' => Value::list([
                    Value::struct([
                        'key' => Value::string('a'),
                        'value' => Value::int(1),
                    ]),
                ]),
            ]],
            DataChunkCodec::rowsFromChunkWithNames($decoded, ['mapping']),
        );
    }
}
