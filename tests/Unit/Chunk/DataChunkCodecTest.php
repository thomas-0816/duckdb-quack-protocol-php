<?php

namespace DuckDB\Quack\Tests\Unit\Chunk;

use DuckDB\Quack\Chunk\DataChunkCodec;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Transport\BinaryWriter;
use DuckDB\Quack\Types\LogicalTypes;
use DuckDB\Quack\Types\VectorType;
use PHPUnit\Framework\TestCase;

final class DataChunkCodecTest extends TestCase
{
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

    public function testRejectsUnknownVectorTypes(): void
    {
        $writer = BinaryWriter::new();
        $writer->writeObject(static function (BinaryWriter $writer): void {
            // Tag 1 used to mean FSST, which is no longer supported.
            $writer->writeField(90, static fn (BinaryWriter $w) => $w->writeUleb(1));
        });
        $reader = new BinaryReader($writer->intoBytes());

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('unknown vector type 1');
        DataChunkCodec::decodeVector($reader, LogicalTypes::varchar(), 1);
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
}
