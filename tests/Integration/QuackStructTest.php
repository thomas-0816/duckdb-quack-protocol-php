<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackStructTest extends TestCase
{
    public function testStructPackAndLiterals(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['s' => ['key1' => 'value1', 'key2' => 42]]],
            $client->fetchAll("SELECT struct_pack(key1 := 'value1', key2 := 42) AS s"),
        );

        self::assertSame(
            [['s' => ['key1' => 'value1', 'key2' => 42]]],
            $client->fetchAll("SELECT {'key1': 'value1', 'key2': 42} AS s"),
        );

        self::assertSame(
            [['s' => ['key1' => 'value1', 'key2' => 42]]],
            $client->fetchAll("SELECT d AS s FROM (SELECT 'value1' AS key1, 42 AS key2) d"),
        );
    }

    public function testStructValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['s' => ['x' => 1, 'y' => 2, 'z' => 3]]],
            $client->fetchAll("SELECT {'x': 1, 'y': 2, 'z': 3} AS s"),
        );

        self::assertSame(
            [['s' => ['yes' => 'duck', 'maybe' => 'goose', 'huh' => null, 'no' => 'heron']]],
            $client->fetchAll("SELECT {'yes': 'duck', 'maybe': 'goose', 'huh': NULL, 'no': 'heron'} AS s"),
        );

        self::assertSame(
            [['s' => ['key1' => 'string', 'key2' => 1, 'key3' => 12.345]]],
            $client->fetchAll("SELECT {'key1': 'string', 'key2': 1, 'key3': 12.345} AS s"),
        );

        self::assertSame(
            [
                [
                    's' => [
                        'birds' => ['yes' => 'duck', 'maybe' => 'goose', 'huh' => null, 'no' => 'heron'],
                        'aliens' => null,
                        'amphibians' => ['yes' => 'frog', 'maybe' => 'salamander', 'huh' => 'dragon', 'no' => 'toad'],
                    ],
                ],
            ],
            $client->fetchAll(
                "SELECT {
                    'birds': {'yes': 'duck', 'maybe': 'goose', 'huh': NULL, 'no': 'heron'}, 'aliens': NULL,
                    'amphibians': {'yes': 'frog', 'maybe': 'salamander', 'huh': 'dragon', 'no': 'toad'}
                 } AS s",
            ),
        );
    }

    public function testStructUpdateAndExtract(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['s' => ['a' => 1, 'b' => 3, 'c' => 4]]],
            $client->fetchAll("SELECT struct_update({'a': 1, 'b': 2}, b := 3, c := 4) AS s"),
        );

        self::assertSame(
            [['x' => 1]],
            $client->fetchAll("SELECT a.x FROM (SELECT {'x': 1, 'y': 2, 'z': 3} AS a)"),
        );

        self::assertSame(
            [['x space' => 1]],
            $client->fetchAll("SELECT a.\"x space\" FROM (SELECT {'x space': 1, 'y': 2, 'z': 3} AS a)"),
        );

        self::assertSame(
            [['a[\'x space\']' => 1]],
            $client->fetchAll("SELECT a['x space'] FROM (SELECT {'x space': 1, 'y': 2, 'z': 3} AS a)"),
        );

        self::assertSame(
            [['struct_extract(struct_pack("x space" := 1, y := 2, z := 3), \'x space\')' => 1]],
            $client->fetchAll("SELECT struct_extract({'x space': 1, 'y': 2, 'z': 3}, 'x space')"),
        );
    }

    public function testUnnestAndExclude(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['x' => 1, 'y' => 2, 'z' => 3]],
            $client->fetchAll("SELECT unnest(a) FROM (SELECT {'x': 1, 'y': 2, 'z': 3} AS a)"),
        );

        self::assertSame(
            [['x' => 1, 'z' => 3]],
            $client->fetchAll("SELECT a.* EXCLUDE ('y') FROM (SELECT {'x': 1, 'y': 2, 'z': 3} AS a)"),
        );

        self::assertSame(
            [['b' => ['y' => 42]]],
            $client->fetchAll("SELECT struct_pack(y := a.x) AS b FROM (SELECT {'x': 42} AS a)"),
        );
    }

    public function testRowAndTuple(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['a' => [1, 2, 'a']]],
            $client->fetchAll("SELECT row(x, x + 1, y) as a FROM (SELECT 1 AS x, 'a' AS y) AS s"),
        );

        self::assertSame(
            [['s' => [1, 2, 'a']]],
            $client->fetchAll("SELECT (x, x + 1, y) AS s FROM (SELECT 1 AS x, 'a' AS y)"),
        );
    }

    public function testStructComparison(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['(struct_pack(k1 := 1, k2 := 0) < struct_pack(k1 := 0, k2 := 1))' => false]],
            $client->fetchAll("SELECT {'k1': 1, 'k2': 0} < {'k1': 0, 'k2': 1}"),
        );

        self::assertSame(
            [['(struct_pack(k1 := 1, k2 := 0) > struct_pack(k3 := 0, k1 := 0))' => true]],
            $client->fetchAll("SELECT {'k1': 1, 'k2': 0} > {'k3': 0, 'k1': 0}"),
        );
    }

    public function testStructWithMaps(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['struct' => ['test' => [[1 => 42.1, 5 => 45.0], [1 => 42.1, 5 => 45.0]]]]],
            $client->fetchAll("SELECT {'test': [MAP([1, 5], [42.1, 45]), MAP([1, 5], [42.1, 45])]} as struct"),
        );
    }

    public function testStructTable(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE quack_struct_test (s STRUCT(v VARCHAR, i INTEGER, a VARCHAR[], j JSON))');
        $client->execute('INSERT INTO quack_struct_test VALUES (?)', [[
            'v' => 'foo',
            'i' => 21,
            'a' => ['b', 'c'],
            'j' => [1, 2, null, 'asd'],
        ]]);

        self::assertSame(
            [
                [
                    's' => [
                        'v' => 'foo',
                        'i' => 21,
                        'a' => ['b', 'c'],
                        'j' => [1, 2, null, 'asd'],
                    ],
                ],
            ],
            $client->fetchAll('SELECT * FROM quack_struct_test'),
        );
    }

    public function testNestedVariantJson(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $values = $client->fetchColumn('SELECT {a:{a:{a:{a:{a:{a:{a:{a:{a:{a:{a:{a:(1, 42.21, null, {a: null})::variant}}}}}}}}}}}} as a');
        self::assertSame(
            '[{"a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":{"a":[1,42.21,null,{"a":null}]}}}}}}}}}}}}]',
            json_encode($values),
        );
    }
}
