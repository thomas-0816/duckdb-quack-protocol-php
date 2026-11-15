<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackTypesTest extends TestCase
{
    public function testUserDefinedStructAndListTypes(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->beginTransaction();

        $client->execute('CREATE TYPE Person AS STRUCT(id INTEGER, name VARCHAR)');
        $client->execute('CREATE TYPE Tags AS VARCHAR[]');
        $client->execute('CREATE TABLE t1 (employee Person, categories Tags)');
        $client->execute('INSERT INTO t1 VALUES (?, ?)', [
            ['id' => 1, 'name' => 'Foo'],
            ['foo', 'bar'],
        ]);
        self::assertSame(
            [
                [
                    'employee' => ['id' => 1, 'name' => 'Foo'],
                    'categories' => ['foo', 'bar'],
                ],
            ],
            $client->fetchAll('SELECT * FROM t1'),
        );
        $client->rollback();
    }
}
