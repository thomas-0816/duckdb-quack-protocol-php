<?php

namespace DuckDB\Quack\Tests\Unit\Transport;

use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Types\BigInt;
use PHPUnit\Framework\TestCase;

final class BinaryReaderTest extends TestCase
{
    public function testReadsSignedFixed64(): void
    {
        $reader = self::readerForSigned([
            PHP_INT_MIN,
            -1,
            0,
            1,
            9223372036854775807,
        ]);

        self::assertSame(PHP_INT_MIN, $reader->readFixedI64());
        self::assertSame(-1, $reader->readFixedI64());
        self::assertSame(0, $reader->readFixedI64());
        self::assertSame(1, $reader->readFixedI64());
        self::assertSame(9223372036854775807, $reader->readFixedI64());
        $reader->assertEof();
    }

    public function testReadsUnsignedFixed64(): void
    {
        $reader = self::readerFor([
            '0',
            '1',
            '9223372036854775807',
            '9223372036854775808',
            '18446744073709551615',
        ]);

        self::assertSame(0, $reader->readFixedU64());
        self::assertSame(1, $reader->readFixedU64());
        self::assertSame(9223372036854775807, $reader->readFixedU64());
        self::assertSame('9223372036854775808', $reader->readFixedU64());
        self::assertSame('18446744073709551615', $reader->readFixedU64());
        $reader->assertEof();
    }

    /**
     * @param list<int> $values
     */
    private static function readerForSigned(array $values): BinaryReader
    {
        $bytes = '';
        foreach ($values as $value) {
            $bytes .= pack('P', $value);
        }

        return new BinaryReader($bytes);
    }

    /**
     * @param list<string> $values
     */
    private static function readerFor(array $values): BinaryReader
    {
        $bytes = '';
        foreach ($values as $value) {
            $bytes .= BigInt::fromString($value)->toBytesLE(8, false);
        }

        return new BinaryReader($bytes);
    }
}
