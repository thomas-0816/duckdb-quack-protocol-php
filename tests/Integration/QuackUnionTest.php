<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackUnionTest extends TestCase
{
    public function testUnionList(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'union' => [
                        ['num' => 2],
                        ['str' => 'ABC'],
                    ],
                ],
            ],
            $client->fetchAll(
                "SELECT [union_value(num := 2), union_value(str := 'ABC')::UNION(str VARCHAR, num INTEGER)] as union",
            ),
        );
    }

    public function testUnionTable(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $client->execute('CREATE TEMP TABLE tbl1 (u UNION(num INTEGER, str VARCHAR))');
        $client->execute("INSERT INTO tbl1 VALUES (1), ('two'), (union_value(str := 'three'))");

        self::assertSame(
            [
                [
                    'u' => ['num' => 1],
                    'str' => null,
                    'str2' => null,
                    't' => 'num',
                ],
                [
                    'u' => ['str' => 'two'],
                    'str' => 'two',
                    'str2' => 'two',
                    't' => 'str',
                ],
                [
                    'u' => ['str' => 'three'],
                    'str' => 'three',
                    'str2' => 'three',
                    't' => 'str',
                ],
            ],
            $client->fetchAll("SELECT u, u.str, union_extract(u, 'str') AS str2, union_tag(u) AS t FROM tbl1"),
        );
    }
}
