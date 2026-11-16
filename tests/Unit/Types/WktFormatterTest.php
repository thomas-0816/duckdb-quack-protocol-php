<?php

namespace DuckDB\Quack\Tests\Unit\Types;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Types\WktFormatter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WktFormatterTest extends TestCase
{
    public function testFormatsGeometryAsWkt(): void
    {
        self::assertSame(
            'POINT (30 10)',
            WktFormatter::fromWkb(self::decodeHex('01010000000000000000003E400000000000002440')),
            'point',
        );
        self::assertSame(
            'LINESTRING (30 10, 10 30, 40 40)',
            WktFormatter::fromWkb(self::decodeHex(
                '0102000000030000000000000000003E40000000000000244000000000000024400000000000003E4000000000000044400000000000004440',
            )),
            'linestring',
        );
        self::assertSame(
            'POLYGON ((30 10, 40 40, 20 40, 10 20, 30 10))',
            WktFormatter::fromWkb(self::decodeHex(
                '010300000001000000050000000000000000003E4000000000000024400000000000004440000000000000444000000000000034400000000000004440000000000000244000000000000034400000000000003E400000000000002440',
            )),
            'polygon',
        );
        self::assertSame(
            'MULTIPOINT (10 40, 40 30, 20 20, 30 10)',
            WktFormatter::fromWkb(self::decodeHex(
                '010400000004000000010100000000000000000024400000000000004440010100000000000000000044400000000000003E4001010000000000000000003440000000000000344001010000000000000000003E400000000000002440',
            )),
            'multipoint',
        );
        self::assertSame(
            'MULTILINESTRING ((10 10, 20 20, 10 40), (40 40, 30 30, 40 20))',
            WktFormatter::fromWkb(self::decodeHex(
                '010500000002000000010200000003000000000000000000244000000000000024400000000000003440000000000000344000000000000024400000000000004440010200000003000000000000000000444000000000000044400000000000003E400000000000003E4000000000000044400000000000003440',
            )),
            'multilinestring',
        );
        self::assertSame(
            'MULTIPOLYGON (((30 20, 45 40, 10 40, 30 20)), ((15 5, 40 10, 10 20, 5 10, 15 5)))',
            WktFormatter::fromWkb(self::decodeHex(
                '010600000002000000010300000001000000040000000000000000003E40000000000000344000000000008046400000000000004440000000000000244000000000000044400000000000003E400000000000003440010300000001000000050000000000000000002E4000000000000014400000000000004440000000000000244000000000000024400000000000003440000000000000144000000000000024400000000000002E400000000000001440',
            )),
            'multipolygon',
        );
        self::assertSame(
            'GEOMETRYCOLLECTION (POINT (40 10), LINESTRING (10 10, 20 20, 10 40), '
                . 'POLYGON ((40 40, 20 45, 45 30, 40 40)))',
            WktFormatter::fromWkb(self::decodeHex(
                '01070000000300000001010000000000000000004440000000000000244001020000000300000000000000000024400000000000002440000000000000344000000000000034400000000000002440000000000000444001030000000100000004000000000000000000444000000000000044400000000000003440000000000080464000000000008046400000000000003E4000000000000044400000000000004440',
            )),
            'geometrycollection',
        );
        self::assertSame(
            'POINT Z (1 2 3)',
            WktFormatter::fromWkb(self::decodeHex(
                '01E9030000000000000000F03F00000000000000400000000000000840',
            )),
            'point z',
        );
        self::assertSame(
            'POINT M (1 2 3)',
            WktFormatter::fromWkb(self::decodeHex(
                '01D1070000000000000000F03F00000000000000400000000000000840',
            )),
            'point m',
        );
        self::assertSame(
            'POINT ZM (1 2 3 4)',
            WktFormatter::fromWkb(self::decodeHex(
                '01B90B0000000000000000F03F000000000000004000000000000008400000000000001040',
            )),
            'point zm',
        );
        self::assertSame(
            'MULTIPOINT Z (1 2 3, 4 5 6)',
            WktFormatter::fromWkb(self::decodeHex(
                '01EC0300000200000001E9030000000000000000F03F0000000000000040000000000000084001E9030000000000000000104000000000000014400000000000001840',
            )),
            'multipoint z',
        );
        self::assertSame(
            'MULTILINESTRING ZM ((1 2 3 4, 5 6 7 8))',
            WktFormatter::fromWkb(self::decodeHex(
                '01BD0B00000100000001BA0B000002000000000000000000F03F000000000000004000000000000008400000000000001040000000000000144000000000000018400000000000001C400000000000002040',
            )),
            'multilinestring zm',
        );
        self::assertSame(
            'GEOMETRYCOLLECTION Z (POINT Z (1 2 3), LINESTRING Z (1 2 3, 4 5 6))',
            WktFormatter::fromWkb(self::decodeHex(
                '01EF0300000200000001E9030000000000000000F03F0000000000000040000000000000084001EA03000002000000000000000000F03F00000000000000400000000000000840000000000000104000000000000014400000000000001840',
            )),
            'geometrycollection z',
        );
    }

    public function testFormatsEmptyGeometryAsWkt(): void
    {
        self::assertSame(
            'POINT EMPTY',
            WktFormatter::fromWkb(self::decodeHex('0101000000000000000000F87F000000000000F87F')),
            'point',
        );
        self::assertSame(
            'POINT Z EMPTY',
            WktFormatter::fromWkb(self::decodeHex(
                '01E9030000000000000000F87F000000000000F87F000000000000F87F',
            )),
            'point z',
        );
        self::assertSame(
            'POINT M EMPTY',
            WktFormatter::fromWkb(self::decodeHex(
                '01D1070000000000000000F87F000000000000F87F000000000000F87F',
            )),
            'point m',
        );
        self::assertSame(
            'POINT ZM EMPTY',
            WktFormatter::fromWkb(self::decodeHex(
                '01B90B0000000000000000F87F000000000000F87F000000000000F87F000000000000F87F',
            )),
            'point zm',
        );
        self::assertSame(
            'LINESTRING EMPTY',
            WktFormatter::fromWkb(self::decodeHex('010200000000000000')),
            'linestring',
        );
        self::assertSame(
            'POLYGON EMPTY',
            WktFormatter::fromWkb(self::decodeHex('010300000000000000')),
            'polygon',
        );
        self::assertSame(
            'MULTIPOINT EMPTY',
            WktFormatter::fromWkb(self::decodeHex('010400000000000000')),
            'multipoint',
        );
        self::assertSame(
            'MULTIPOINT Z EMPTY',
            WktFormatter::fromWkb(self::decodeHex('01EC03000000000000')),
            'multipoint z',
        );
        self::assertSame(
            'MULTILINESTRING EMPTY',
            WktFormatter::fromWkb(self::decodeHex('010500000000000000')),
            'multilinestring',
        );
        self::assertSame(
            'MULTIPOLYGON EMPTY',
            WktFormatter::fromWkb(self::decodeHex('010600000000000000')),
            'multipolygon',
        );
        self::assertSame(
            'GEOMETRYCOLLECTION EMPTY',
            WktFormatter::fromWkb(self::decodeHex('010700000000000000')),
            'geometrycollection',
        );
        self::assertSame(
            'GEOMETRYCOLLECTION Z EMPTY',
            WktFormatter::fromWkb(self::decodeHex('01EF03000000000000')),
            'geometrycollection z',
        );
        self::assertSame(
            'GEOMETRYCOLLECTION (POINT EMPTY, POINT (1 2))',
            WktFormatter::fromWkb(self::decodeHex(
                '0107000000020000000101000000000000000000F87F000000000000F87F0101000000000000000000F03F0000000000000040',
            )),
            'empty point inside geometrycollection',
        );
        self::assertSame(
            'MULTIPOINT (EMPTY, 1 2)',
            WktFormatter::fromWkb(self::decodeHex(
                '0104000000020000000101000000000000000000F87F000000000000F87F0101000000000000000000F03F0000000000000040',
            )),
            'empty point inside multipoint',
        );
        self::assertSame(
            'MULTILINESTRING (EMPTY, (1 2, 3 4))',
            WktFormatter::fromWkb(self::decodeHex(
                '010500000002000000010200000000000000010200000002000000000000000000F03F000000000000004000000000000008400000000000001040',
            )),
            'empty linestring inside multilinestring',
        );
    }

    public function testFormatsNumbersLikeDuckDb(): void
    {
        self::assertSame(
            'POINT (0.1 1.2345678901234567)',
            WktFormatter::fromWkb(self::decodeHex(
                '01010000009A9999999999B93FFB598C42CAC0F33F',
            )),
            'shortest round-trip',
        );
        self::assertSame(
            'POINT (-0 1)',
            WktFormatter::fromWkb(self::decodeHex(
                '01010000000000000000000080000000000000F03F',
            )),
            'negative zero',
        );
        self::assertSame(
            'POINT (1234567890123456.8 0.0001)',
            WktFormatter::fromWkb(self::decodeHex(
                '010100000003EB2AF2548B11432D431CEBE2361A3F',
            )),
            'fixed through 1e15',
        );
        self::assertSame(
            'POINT (1e+16 1)',
            WktFormatter::fromWkb(self::decodeHex(
                '01010000000080E03779C34143000000000000F03F',
            )),
            'scientific from 1e16',
        );
        self::assertSame(
            'POINT (1e-05 -1e-100)',
            WktFormatter::fromWkb(self::decodeHex(
                '0101000000F168E388B5F8E43E30058EE42EFF2BAB',
            )),
            'small exponents',
        );
        self::assertSame(
            'POINT (1e-07 1e+20)',
            WktFormatter::fromWkb(self::decodeHex(
                '010100000048AFBC9AF2D77A3E408CB5781DAF1544',
            )),
            'large exponent',
        );
    }

    public function testRejectsBigEndianWkb(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('unsupported WKB byte order 0');

        WktFormatter::fromWkb(self::decodeHex('0000000001403E0000000000004024000000000000'));
    }

    public function testRejectsUnsupportedGeometryType(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('unsupported geometry type 8 in WKB');

        WktFormatter::fromWkb(self::decodeHex('0108000000'));
    }

    public function testRejectsTruncatedWkb(): void
    {
        $this->expectException(ProtocolException::class);

        WktFormatter::fromWkb(self::decodeHex('01010000000000000000003E40'));
    }

    public function testRejectsInconsistentDimensionsInCollection(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('geometry has inconsistent Z/M dimensions');

        // GEOMETRYCOLLECTION containing a 3D point, but the collection is 2D.
        WktFormatter::fromWkb(self::decodeHex(
            '01070000000100000001E9030000000000000000F03F00000000000000400000000000000840',
        ));
    }

    public function testRejectsInconsistentDimensionsInMultipoint(): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('geometry has inconsistent Z/M dimensions in MULTIPOINT');

        // A 2D MULTIPOINT whose part header claims Z.
        WktFormatter::fromWkb(self::decodeHex(
            '01040000000100000001E9030000000000000000F03F000000000000004000000000000008400000000000001040',
        ));
    }

    public function testRejectsExcessiveNesting(): void
    {
        $wkb = '';
        for ($depth = 0; $depth < 16; $depth++) {
            $wkb .= pack('CVV', 1, 7, 1);
        }
        $wkb .= pack('CV', 1, 1) . pack('ee', 0.0, 0.0);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('geometry exceeds maximum recursion depth of 16');

        WktFormatter::fromWkb($wkb);
    }

    private static function decodeHex(string $hex): string
    {
        $wkb = hex2bin($hex);
        if ($wkb === false) {
            throw new InvalidArgumentException('invalid WKB hex string');
        }

        return $wkb;
    }
}
