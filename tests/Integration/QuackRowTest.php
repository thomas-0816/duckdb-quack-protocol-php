<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackRowTest extends TestCase
{
    public function testRowAndStructPack(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"row"()' => [],
                    'struct_pack()' => [],
                    'typeof("row"())' => 'TUPLE',
                    'CAST("row"() AS STRUCT)' => [],
                    'CAST("row"() AS VARCHAR)' => '()',
                    'CAST(NULL AS STRUCT)' => null,
                ],
            ],
            $client->fetchAll(
                'SELECT row(), struct_pack(), typeof(row()), row()::STRUCT, row()::VARCHAR, NULL::STRUCT',
            ),
        );
    }

    public function testRowEquality(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['("row"() = "row"())' => true, '("row"() IS NOT DISTINCT FROM "row"())' => true]],
            $client->fetchAll('SELECT row() = row(), row() IS NOT DISTINCT FROM row()'),
        );
    }

    public function testNestedRow(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    'struct_pack(a := "row"(), b := 1)' => ['a' => [], 'b' => 1],
                    'list_value("row"(), "row"())' => [[], []],
                    'typeof(struct_pack(a := "row"()))' => 'STRUCT(a TUPLE)',
                ],
            ],
            $client->fetchAll("SELECT {'a': row(), 'b': 1}, [row(), row()], typeof({'a': row()})"),
        );
    }

    public function testRowTable(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE quack_row_test(i INTEGER, s STRUCT)');
        $client->execute('INSERT INTO quack_row_test VALUES (1, row()), (2, NULL)');

        self::assertSame(
            [
                ['i' => 1, 's' => []],
                ['i' => 2, 's' => null],
            ],
            $client->fetchAll('SELECT i, s FROM quack_row_test ORDER BY i'),
        );

        self::assertSame(
            [
                ['i' => 1],
                ['i' => 2],
            ],
            $client->fetchAll('SELECT i FROM quack_row_test ORDER BY s, i'),
        );

        self::assertSame(
            [['count_star()' => 1]],
            $client->fetchAll('SELECT count(*) FROM quack_row_test WHERE s IS NOT NULL'),
        );
    }

    public function testStructConcat(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['struct_concat("row"(), "row"(1, 2))' => [1, 2]]],
            $client->fetchAll('SELECT struct_concat(row(), row(1, 2))'),
        );
    }

    public function testStructContains(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['struct_contains("row"(), \'a\')' => false, 'struct_contains("row"(), 5)' => false]],
            $client->fetchAll("SELECT struct_contains(row(), 'a'), struct_contains(row(), 5)"),
        );
    }

    public function testStructConcatEmpty(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    'struct_concat("row"(), "row"())' => [],
                    'struct_concat("row"(), struct_pack(x := 1))' => ['x' => 1],
                    'struct_concat(struct_pack(x := 1), "row"())' => ['x' => 1],
                ],
            ],
            $client->fetchAll("SELECT struct_concat(row(), row()), struct_concat(row(), {'x': 1}), struct_concat({'x': 1}, row())"),
        );
    }

    public function testStructExtract(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['struct_extract(struct_pack(a := 1), \'a\')' => 1]],
            $client->fetchAll("SELECT struct_extract({'a': 1}, 'a')"),
        );
    }

    public function testUnnestRow(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['element1' => 1, 'element2' => 2]],
            $client->fetchAll('SELECT unnest(row(1, 2))'),
        );

        self::assertSame(
            [['a' => 1, 'b' => 1]],
            $client->fetchAll("SELECT unnest(struct_concat({'a': 1}, {'b': 1}))"),
        );
    }

    public function testEmptyRowErrors(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        try {
            $client->fetchAll("SELECT struct_extract(row(), 'a')");
            self::fail('expected the query to fail');
        } catch (ServerException $e) {
            self::assertStringContainsString('Can\'t extract something from an empty struct', $e->getMessage());
        }

        try {
            $client->fetchAll('SELECT row()[1]');
            self::fail('expected the query to fail');
        } catch (ServerException $e) {
            self::assertStringContainsString('Can\'t extract something from an empty struct', $e->getMessage());
        }

        try {
            $client->fetchAll('SELECT unnest(row())');
            self::fail('expected the query to fail');
        } catch (ServerException $e) {
            self::assertStringContainsString('UNNEST of an empty struct is not supported', $e->getMessage());
        }
    }
}
