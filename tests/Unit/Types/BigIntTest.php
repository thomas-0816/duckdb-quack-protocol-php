<?php

namespace DuckDB\Quack\Tests\Unit\Types;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Types\BigInt;
use PHPUnit\Framework\TestCase;

final class BigIntTest extends TestCase
{
    public function testDecimalStringsRoundTrip(): void
    {
        $cases = [
            ['0', '0'],
            ['-0', '0'],
            ['00042', '42'],
            ['+7', '7'],
            ['9223372036854775807', '9223372036854775807'],
            ['18446744073709551615', '18446744073709551615'],
            [
                '170141183460469231731687303715884105727',
                '170141183460469231731687303715884105727',
            ],
            [
                '-170141183460469231731687303715884105728',
                '-170141183460469231731687303715884105728',
            ],
        ];

        foreach ($cases as [$input, $canonical]) {
            self::assertSame($canonical, (string) BigInt::fromString($input));
        }
    }

    public function testArithmeticStaysExactBeyondPhpIntegerRange(): void
    {
        $a = BigInt::fromString('170141183460469231731687303715884105727');
        $b = BigInt::fromString('1');

        self::assertSame(
            '170141183460469231731687303715884105728',
            (string) $a->add($b),
        );
        self::assertSame(
            '170141183460469231731687303715884105726',
            (string) $a->sub($b),
        );
        self::assertSame('340282366920938463463374607431768211454', (string) $a->mul(BigInt::fromInt(2)));
    }

    public function testDivisionAndModuloFollowTruncation(): void
    {
        [$quotient, $remainder] = BigInt::fromString('-17')->divMod(BigInt::fromInt(5));

        self::assertSame('-3', (string) $quotient);
        self::assertSame('-2', (string) $remainder);
    }

    public function testLittleEndianBytesRoundTrip(): void
    {
        $value = BigInt::fromString('170141183460469231731687303715884105727');
        $bytes = $value->toBytesLE(16, true);

        self::assertSame(16, strlen($bytes));
        self::assertSame((string) $value, (string) BigInt::fromBytesLE($bytes, true));
    }

    public function testFromBytesLeDecodesWidthsAndSigns(): void
    {
        $cases = [
            ['-1', "\xff", true],
            ['-128', "\x80", true],
            ['255', "\xff", false],
            ['-8388608', "\0\0\x80", true],
            ['0', str_repeat("\0", 8), true],
            ['1', "\x01" . str_repeat("\0", 7), true],
            ['-1', str_repeat("\xff", 8), true],
            ['-1', str_repeat("\xff", 9), true],
            ['-9223372036854775808', str_repeat("\0", 7) . "\x80", true],
            ['9223372036854775807', str_repeat("\xff", 7) . "\x7f", true],
            ['18446744073709551615', str_repeat("\xff", 8), false],
            ['18446744073709551616', str_repeat("\0", 8) . "\x01", false],
            ['4722366482869645213695', str_repeat("\xff", 9), false],
            [
                '170141183460469231731687303715884105727',
                str_repeat("\xff", 15) . "\x7f",
                true,
            ],
            [
                '-170141183460469231731687303715884105728',
                str_repeat("\0", 15) . "\x80",
                true,
            ],
            [
                '340282366920938463463374607431768211455',
                str_repeat("\xff", 16),
                false,
            ],
        ];

        foreach ($cases as [$expected, $bytes, $signed]) {
            self::assertSame($expected, (string) BigInt::fromBytesLE($bytes, $signed));
        }
    }

    public function testToIntOrStringUsesStringOutsidePhpIntegerRange(): void
    {
        self::assertSame(42, BigInt::fromString('42')->toIntOrString());
        self::assertSame(
            '170141183460469231731687303715884105727',
            BigInt::fromString('170141183460469231731687303715884105727')->toIntOrString(),
        );
    }

    public function testInvalidStringsAreRejected(): void
    {
        foreach (['', '   ', '-', '12x', '1.5'] as $input) {
            try {
                BigInt::fromString($input);
                self::fail("accepted invalid integer \"{$input}\"");
            } catch (ProtocolException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
