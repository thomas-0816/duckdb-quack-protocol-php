<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackBlobTest extends TestCase
{
    public function testBlobValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            "\xAA",
            $client->fetchValue('SELECT \'\xAA\'::BLOB AS a'),
        );
        self::assertSame(
            "\xAA\xAB\xAC",
            $client->fetchValue('SELECT \'\xAA\xAB\xAC\'::BLOB AS a'),
        );
    }
}
