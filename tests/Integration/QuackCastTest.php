<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackCastTest extends TestCase
{
    public function testCastScalars(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'CAST(42.5 AS VARCHAR)' => '42.5',
                    'CAST(3.1 AS INTEGER)' => 3,
                ],
            ],
            $client->fetchAll('SELECT CAST(42.5 AS VARCHAR), CAST(3.1 AS INTEGER)'),
        );
    }

    public function testCastFloatAndList(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'CAST(0.9 AS FLOAT)' => 0.9,
                    'CAST(3.1 AS FLOAT)' => 3.1,
                    'CAST(list_value(1, 2, 3) AS VARCHAR[])' => ['1', '2', '3'],
                ],
            ],
            $client->fetchAll(
                'SELECT CAST(0.9 AS FLOAT), CAST(3.1 AS FLOAT), CAST([1, 2, 3] AS VARCHAR[])',
            ),
        );
    }

    public function testCastStructAddsNull(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'CAST(struct_pack(a := 42) AS STRUCT(a VARCHAR))' => [
                        'a' => '42',
                    ],
                    'CAST(struct_pack(a := 42) AS STRUCT(a VARCHAR, b VARCHAR))' => [
                        'a' => '42',
                        'b' => null,
                    ],
                ],
            ],
            $client->fetchAll(
                "SELECT CAST({'a': 42} AS STRUCT(a VARCHAR)), CAST({'a': 42} AS STRUCT(a VARCHAR, b VARCHAR))",
            ),
        );
    }

    public function testCastStructReorders(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'CAST(struct_pack(a := 42, b := 43) AS STRUCT(a VARCHAR))' => [
                        'a' => '42',
                    ],
                    'CAST(struct_pack(a := 42, b := 84) AS STRUCT(b VARCHAR, a VARCHAR))' => [
                        'b' => '84',
                        'a' => '42',
                    ],
                ],
            ],
            $client->fetchAll(
                "SELECT CAST({'a': 42, 'b': 43} AS STRUCT(a VARCHAR)), CAST({'a': 42, 'b': 84} AS STRUCT(b VARCHAR, a VARCHAR))",
            ),
        );
    }

    public function testStructList(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'list_value(struct_pack(a := 42), struct_pack(b := 84))' => [
                        [
                            'a' => 42,
                            'b' => null,
                        ],
                        [
                            'a' => null,
                            'b' => 84,
                        ],
                    ],
                ],
            ],
            $client->fetchAll("SELECT [{'a': 42}, {'b': 84}]"),
        );
    }

    public function testNestedStructUnion(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'c' => [
                        'outer1' => [
                            'inner1' => 42,
                            'inner2' => '42',
                            'inner3' => null,
                        ],
                        'outer2' => null,
                    ],
                ],
                [
                    'c' => [
                        'outer1' => [
                            'inner1' => null,
                            'inner2' => 'hello',
                            'inner3' => 'world',
                        ],
                        'outer2' => '100',
                    ],
                ],
            ],
            $client->fetchAll(
                "FROM (SELECT {'outer1': {'inner1': 42, 'inner2': 42}} AS c\n"
                . "    UNION SELECT {'outer1': {'inner2': 'hello', 'inner3': 'world'}, 'outer2': '100'} AS c) a\n"
                . '    order by a.c.outer1.inner2',
            ),
        );
    }

    public function testCastOutOfRangeAndTinyInt(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        try {
            $client->fetchValue('SELECT CAST(999 AS TINYINT)');
            self::fail('expected the out-of-range cast to throw');
        } catch (ServerException $e) {
            self::assertStringContainsString(
                "Type INT32 with value 999 can't be cast because the value is out of range for the destination type INT8",
                $e->getMessage(),
            );
        }

        self::assertSame(121, $client->fetchValue('SELECT CAST(121 AS TINYINT)'));
    }
}
