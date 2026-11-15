<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackScalarTest extends TestCase
{
    public function testScalarValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'NULL' => null,
                    'true' => true,
                    'false' => false,
                    'CAST(NULL AS BOOLEAN)' => null,
                    "CAST('1992-09-20' AS DATE)" => '1992-09-20',
                ],
            ],
            $client->fetchAll("SELECT null, true, false, NULL::BOOLEAN, DATE '1992-09-20'"),
        );
    }

    public function testNullComparison(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [['(NULL = NULL)' => null]],
            $client->fetchAll('SELECT NULL = NULL'),
        );
    }

    public function testTypeValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    "make_type('INTEGER')" => 'INTEGER',
                    'get_type(42)' => 'INTEGER',
                    'CAST(NULL AS TYPE)' => null,
                ],
            ],
            $client->fetchAll("SELECT make_type('INTEGER'), get_type(42), NULL::TYPE"),
        );
    }
}
