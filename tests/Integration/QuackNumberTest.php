<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackNumberTest extends TestCase
{
    public function testDivisionByZero(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['(0 / 0)' => '<nan>', 'TRY((0 // 0))' => null]],
            self::normalizeNans($client->fetchAll('SELECT 0/0, try(0//0)')),
        );

        self::assertSame(
            [[
                '(1 / 0)' => INF,
                '(-1 / 0)' => -INF,
                'TRY((1 // 0))' => null,
                'TRY((-1 // 0))' => null,
            ]],
            $client->fetchAll('SELECT 1/0, -1/0, try(1//0), try(-1//0)'),
        );

        self::assertSame(
            [[
                '(1 / CAST(0 AS FLOAT))' => INF,
                '(-1 / CAST(0 AS FLOAT))' => -INF,
                '(0 / CAST(0 AS FLOAT))' => '<nan>',
            ]],
            self::normalizeNans($client->fetchAll('SELECT 1/0::float, -1/0::float, 0/0::float')),
        );
    }

    public function testNumericLiterals(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [[
                'CAST(0.9 AS FLOAT)' => 0.9,
                'pi()' => 3.141592653589793,
                '9223372036854775808' => '9223372036854775808',
                '-9223372036854775809' => '-9223372036854775809',
            ]],
            $client->fetchAll('select 0.9::float, pi(), 9223372036854775808, -9223372036854775809'),
        );

        self::assertSame(
            [['1.5' => 1.5, '.50' => 0.5, '2.' => 2.0]],
            $client->fetchAll('SELECT 1.5, .50, 2.'),
        );

        self::assertSame(
            [['1e2' => 100.0, '6.02214e23' => 6.02214E+23, '1e-10' => 1.0E-10]],
            $client->fetchAll('SELECT 1e2, 6.02214e23, 1e-10'),
        );

        self::assertSame(
            [[
                '100_000_000' => 100_000_000,
                "CAST('0xFF_FF' AS INTEGER)" => 65535,
                '1_2.1_2E0_1' => 121.2,
                "CAST('0b0_1_0_1' AS INTEGER)" => 5,
            ]],
            $client->fetchAll("SELECT 100_000_000, '0xFF_FF'::INTEGER, 1_2.1_2E0_1, '0b0_1_0_1'::INTEGER"),
        );
    }

    public function testIntegerTypesRoundTrip(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE t (i INTEGER, ui UINTEGER, b BIGINT, b2 BIGINT, ub UBIGINT, h HUGEINT, u UHUGEINT)');
        $client->execute('INSERT INTO t VALUES (?, ?, ?, ?, ?, ?, ?)', [
            1,
            2,
            9_223_372_036_854_775_806,
            -9_223_372_036_854_775_806,
            '18446744073709551614',
            '170141183460469231731687303715884105726',
            '340282366920938463463374607431768211455',
        ]);

        self::assertSame(
            [[
                'i' => 1,
                'ui' => 2,
                'b' => 9_223_372_036_854_775_806,
                'b2' => -9_223_372_036_854_775_806,
                'ub' => '18446744073709551614',
                'h' => '170141183460469231731687303715884105726',
                'u' => '340282366920938463463374607431768211455',
            ]],
            $client->fetchAll('SELECT * FROM t'),
        );
    }

    public function testBignum(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [[
                "CAST('3402823669209384634633746074317682114571111' AS bignum)" => '3402823669209384634633746074317682114571111',
            ]],
            $client->fetchAll("SELECT '3402823669209384634633746074317682114571111'::bignum"),
        );
    }

    public function testDecimals(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [[
                'CAST(1.23 AS DECIMAL(4, 2))' => 1.23,
                'CAST(0.001 AS DECIMAL(10, 3))' => 0.001,
                '(-(CAST(3.14 AS DECIMAL(4, 2))))' => -3.14,
                'CAST(0 AS DECIMAL(5, 2))' => 0.0,
            ]],
            $client->fetchAll('SELECT 1.23::DECIMAL(4,2), 0.001::DECIMAL(10,3), -3.14::DECIMAL(4,2), 0::DECIMAL(5,2)'),
        );

        self::assertSame(
            [[
                'CAST(1.23 AS DECIMAL(13, 3))' => 1.23,
                'CAST(0.001 AS DECIMAL(14, 4))' => 0.001,
                '(-(CAST(3.14 AS DECIMAL(15, 5))))' => -3.14,
                'CAST(0 AS DECIMAL(16, 6))' => 0.0,
            ]],
            $client->fetchAll('SELECT 1.23::DECIMAL(13,3), 0.001::DECIMAL(14,4), -3.14::DECIMAL(15,5), 0::DECIMAL(16,6)'),
        );

        self::assertSame(
            [[
                'CAST(999999999999999 AS DECIMAL(15, 0))' => 999999999999999.0,
                'CAST(0.99 AS DECIMAL(15, 15))' => 0.99,
            ]],
            $client->fetchAll('SELECT 999999999999999::DECIMAL(15,0), 0.99::DECIMAL(15,15)'),
        );

        self::assertSame(
            [[
                'CAST(1000000000000000 AS DECIMAL(16, 0))' => 1000000000000000.0,
                'CAST(0.9999999999999999 AS DECIMAL(16, 16))' => '0.9999999999999999',
            ]],
            $client->fetchAll('SELECT 1000000000000000::DECIMAL(16,0), 0.9999999999999999::DECIMAL(16,16)'),
        );

        self::assertSame(
            [[
                'CAST(0.311699786984687608 AS DECIMAL(18, 18))' => '0.311699786984687608',
                'CAST(0.45971054587213542206239787480916869700 AS DECIMAL(38, 38))' => '0.45971054587213539913392807288705646592',
                '(-(CAST(0.311699786984687608 AS DECIMAL(18, 18))))' => '-0.311699786984687608',
            ]],
            $client->fetchAll('SELECT 0.311699786984687608::DECIMAL(18,18), 0.45971054587213542206239787480916869700::DECIMAL(38,38), -0.311699786984687608::DECIMAL(18,18)'),
        );

        self::assertSame(
            [['CAST(NULL AS DECIMAL(10, 2))' => null]],
            $client->fetchAll('SELECT CAST(NULL AS DECIMAL(10,2))'),
        );
    }

    public function testNonFiniteFunctions(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['ln(-1)' => '<nan>', 'sqrt(-1)' => '<nan>']],
            self::normalizeNans($client->fetchAll('SELECT ln(-1), sqrt(-1)')),
        );
    }

    public function testSmallIntegerTypes(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [[
                'CAST(10 AS SMALLINT)' => 10,
                'CAST(11 AS USMALLINT)' => 11,
                'CAST(12 AS HUGEINT)' => 12,
                'CAST(13 AS UHUGEINT)' => 13,
            ]],
            $client->fetchAll('SELECT 10::SMALLINT, 11::USMALLINT, 12::HUGEINT, 13::UHUGEINT'),
        );
    }

    public function testHighPrecisionDecimals(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [[
                "CAST(((('1' || repeat('2', 36)) || '3.') || repeat('4', 38)) AS DECIMAL(38, 0))" => '12222222222222222222222222222222222223',
            ]],
            $client->fetchAll("SELECT ('1'||repeat('2', 36)||'3.'||repeat('4', 38))::DECIMAL(38,0)"),
        );

        self::assertSame(
            [[
                "CAST(((('-1' || repeat('2', 36)) || '3.') || repeat('4', 38)) AS DECIMAL(38, 0))" => '-12222222222222222222222222222222222223',
            ]],
            $client->fetchAll("SELECT ('-1'||repeat('2', 36)||'3.'||repeat('4', 38))::DECIMAL(38,0)"),
        );

        self::assertSame(
            [[
                "CAST(((('1' || repeat('2', 35)) || '3.') || repeat('4', 38)) AS DECIMAL(38, 1))" => '1222222222222222222222222222222222223.4',
            ]],
            $client->fetchAll("SELECT ('1'||repeat('2', 35)||'3.'||repeat('4', 38))::DECIMAL(38,1)"),
        );

        self::assertSame(
            [[
                "CAST(((('-1' || repeat('2', 35)) || '3.') || repeat('4', 38)) AS DECIMAL(38, 1))" => '-1222222222222222222222222222222222223.4',
            ]],
            $client->fetchAll("SELECT ('-1'||repeat('2', 35)||'3.'||repeat('4', 38))::DECIMAL(38,1)"),
        );

        self::assertSame(
            [[
                "CAST((('1.2' || repeat('3', 35)) || '45') AS DECIMAL(38, 37))" => '1.2333333333333333333333333333333333335',
            ]],
            $client->fetchAll("SELECT ('1.2'||repeat('3', 35)||'45')::DECIMAL(38,37)"),
        );

        self::assertSame(
            [[
                "CAST((('-1.2' || repeat('3', 35)) || '45') AS DECIMAL(38, 37))" => '-1.2333333333333333333333333333333333335',
            ]],
            $client->fetchAll("SELECT ('-1.2'||repeat('3', 35)||'45')::DECIMAL(38,37)"),
        );
    }

    public function testFloatingPointOperations(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [[
                '(5 / 3)' => 1.6666666666666667,
                '(-5 / 3)' => -1.6666666666666667,
                '(CAST(0.2 AS double) + CAST(0.7 AS double))' => 0.8999999999999999,
                '((-(CAST(0.2 AS double))) + CAST(0.7 AS double))' => 0.49999999999999994,
                "CAST('1.1111111111111111' AS double)" => 1.1111111111111112,
                "CAST('-1.1111111111111111' AS double)" => -1.1111111111111112,
            ]],
            $client->fetchAll("SELECT 5 / 3, -5 / 3, 0.2::double + 0.7::double, -0.2::double + 0.7::double, '1.1111111111111111'::double, '-1.1111111111111111'::double"),
        );
    }

    private static function normalizeNans(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::normalizeNans(...), $value);
        }
        if (is_float($value) && is_nan($value)) {
            return '<nan>';
        }

        return $value;
    }
}
