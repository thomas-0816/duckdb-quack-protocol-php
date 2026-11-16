<?php

namespace DuckDB\Quack\Tests\Unit\Chunk;

use DuckDB\Quack\Chunk\DataChunkCodec;
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

    public function testDecodesFsstTaggedStringVectorsAsFlatVectors(): void
    {
        $reader = self::fsstStringVector(['alpha', 'beta', 'gamma']);
        $decoded = DataChunkCodec::decodeVector($reader, LogicalTypes::varchar(), 3);
        $reader->assertEof();

        self::assertSame(VectorType::Fsst, $decoded->vectorType);
        self::assertSame(['alpha', 'beta', 'gamma'], $decoded->values);
    }

    /**
     * @param list<string> $values
     */
    private static function fsstStringVector(array $values): BinaryReader
    {
        $lengths = '';
        $bytes = '';
        foreach ($values as $value) {
            $lengths .= pack('V', strlen($value));
            $bytes .= $value;
        }

        $writer = BinaryWriter::new();
        $writer->writeObject(static function (BinaryWriter $writer) use ($lengths, $bytes): void {
            $writer->writeField(
                90,
                static fn (BinaryWriter $w) => $w->writeUleb(VectorType::Fsst->value),
            );
            $writer->writeField(100, static fn (BinaryWriter $w) => $w->writeBool(false));
            $writer->writeField(107, static fn (BinaryWriter $w) => $w->writeUleb(strlen($bytes)));
            $writer->writeField(108, static fn (BinaryWriter $w) => $w->writeBlob($lengths));
            $writer->writeField(109, static fn (BinaryWriter $w) => $w->writeBlob($bytes));
        });

        return new BinaryReader($writer->intoBytes());
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
