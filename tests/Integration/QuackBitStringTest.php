<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackBitStringTest extends TestCase
{
    public function testBitValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    'b' => '101010',
                    'b2' => '1111',
                    'b3' => null,
                    'b5' => '0',
                    'b6' => '1',
                ],
            ],
            $client->fetchAll(
                "SELECT '101010'::BIT AS b, '1111'::BIT AS b2, NULL::BIT AS b3, '0'::BIT AS b5, '1'::BIT AS b6",
            ),
        );
    }

    public function testBitCount(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['bitcount' => 3]],
            $client->fetchAll("SELECT BIT_COUNT('101010'::BIT) AS bitcount"),
        );
    }

    public function testBitConcat(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['concat' => '1010101011110000']],
            $client->fetchAll(
                "SELECT ('10101010'::BIT || '11110000'::BIT) AS concat",
            ),
        );
    }

    public function testBitTable(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE bit_table (id INTEGER, b BIT)');
        $client->execute(
            'INSERT INTO bit_table VALUES (?, CAST(? AS BIT))',
            [1, '101010'],
        );
        $client->execute(
            'INSERT INTO bit_table VALUES (?, CAST(? AS BIT))',
            [2, '110011'],
        );

        self::assertSame(
            [
                ['id' => 1, 'b' => '101010'],
                ['id' => 2, 'b' => '110011'],
            ],
            $client->fetchAll('SELECT * FROM bit_table ORDER BY id'),
        );
    }
}
