<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackArrayTest extends TestCase
{
    public function testArrayValue(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['aa' => [1, 2, 3]]],
            $client->fetchAll('SELECT array_value(1, 2, 3) as aa'),
        );
    }

    public function testArrayIndex(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['aa' => 2]],
            $client->fetchAll('SELECT array_value(1, 2, 3)[2] as aa'),
        );
    }

    public function testArrayLiteral(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['aa' => [3, 2, 1]]],
            $client->fetchAll('SELECT [3, 2, 1]::INTEGER[3] as aa'),
        );
    }

    public function testNestedArrays(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['aa' => [[1, 2], [3, 4], [5, 6]]]],
            $client->fetchAll(
                'SELECT array_value(array_value(1, 2), array_value(3, 4), array_value(5, 6)) as aa',
            ),
        );
    }

    public function testArrayOfStructs(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    'aa' => [
                        ['a' => 1, 'b' => 2],
                        ['a' => 3, 'b' => 4],
                    ],
                ],
            ],
            $client->fetchAll(
                "SELECT array_value({'a': 1, 'b': 2}, {'a': 3, 'b': 4}) as aa",
            ),
        );
    }

    public function testArrayTable(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE array_table (id INTEGER, arr INTEGER[3])');
        $client->execute(
            'INSERT INTO array_table VALUES (10, [1, 2, 3]), (20, [4, 5, 6])',
        );

        $elements = [
            ['id' => 10, 'element' => 1],
            ['id' => 20, 'element' => 4],
        ];
        self::assertSame(
            $elements,
            $client->fetchAll('SELECT id, arr[1] AS element FROM array_table'),
        );
        self::assertSame(
            $elements,
            $client->fetchAll('SELECT id, list_extract(arr, 1) AS element FROM array_table'),
        );
        self::assertSame(
            $elements,
            $client->fetchAll('SELECT id, array_extract(arr, 1) AS element FROM array_table'),
        );
        self::assertSame(
            [
                ['id' => 10, 'elements' => [1, 2]],
                ['id' => 20, 'elements' => [4, 5]],
            ],
            $client->fetchAll('SELECT id, arr[1:2] AS elements FROM array_table'),
        );
    }

    public function testFloatArrays(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE x (i INTEGER, v FLOAT[3])');
        $client->execute('CREATE TEMP TABLE y (i INTEGER, v FLOAT[3])');
        $client->execute(
            'INSERT INTO x VALUES (1, array_value(1.0::FLOAT, 2.0::FLOAT, 3.0::FLOAT))',
        );
        $client->execute(
            'INSERT INTO y VALUES (1, array_value(2.1::FLOAT, 3.2::FLOAT, 4.3::FLOAT))',
        );

        self::assertSame(
            [['i' => 1, 'v' => [1.0, 2.0, 3.0]]],
            $client->fetchAll('SELECT * FROM x'),
        );

        self::assertSame(
            [['i' => 1, 'v' => [2.1, 3.2, 4.3]]],
            $client->fetchAll('SELECT * FROM y'),
        );
    }

    public function testHnswIndex(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('INSTALL vss; LOAD vss');
        $client->execute(
            'CREATE TEMP TABLE table1 (id int primary key, embeddings float[3])',
        );
        $client->execute(
            "CREATE INDEX table1_hnsw ON table1 USING HNSW (embeddings) WITH (metric = 'cosine')",
        );
        $client->execute('INSERT INTO table1 VALUES (1, [1, 2, 3])');
        $client->execute('INSERT INTO table1 VALUES (2, [4, 5, 6])');

        $row = $client->fetchOne(
            'SELECT id, array_cosine_distance(embeddings, [1.1, 2.1, 3.1]::FLOAT[3]) as distance '
            . 'FROM table1 WHERE distance <= 0.01 ORDER BY distance',
        );
        self::assertSame(1, $row['id'] ?? null);
        self::assertEqualsWithDelta(0.0001407, $row['distance'] ?? null, 0.0000001);
    }

    public function testNestedVariantArray(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $expected = [[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[[['foo' => 42]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]]];
        self::assertSame(
            $expected,
            $client->fetchValue(
                'SELECT ' . str_repeat('[', 40) . '{foo:42}::variant' . str_repeat(']', 40) . ' as a',
            ),
        );
    }
}
