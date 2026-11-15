<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackEnumTest extends TestCase
{
    public function testEnumType(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $client->execute('DROP TYPE IF EXISTS mood');
        $client->execute("CREATE TYPE mood AS ENUM ('sad', 'ok', 'happy')");

        self::assertSame(
            [
                [
                    'm1' => 'sad',
                    'm2' => 'happy',
                    'mn' => null,
                ],
            ],
            $client->fetchAll("SELECT 'sad'::mood AS m1, 'happy'::mood AS m2, NULL::mood AS mn"),
        );
    }

    public function testEnumTable(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $client->execute('DROP TYPE IF EXISTS mood');
        $client->execute("CREATE TYPE mood AS ENUM ('sad', 'ok', 'happy')");
        $client->execute('CREATE TEMP TABLE enum_table (id INTEGER, m mood)');
        $client->execute("INSERT INTO enum_table VALUES (1, 'ok'), (2, 'happy'), (3, 'sad')");

        self::assertSame(
            [
                ['id' => 1, 'm' => 'ok'],
                ['id' => 2, 'm' => 'happy'],
                ['id' => 3, 'm' => 'sad'],
            ],
            $client->fetchAll('SELECT * FROM enum_table ORDER BY id'),
        );
    }

    public function testInlineEnum(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    "CAST('clubs' AS ENUM('spades', 'hearts', 'diamonds', 'clubs'))" => 'clubs',
                ],
            ],
            $client->fetchAll("SELECT 'clubs'::ENUM ('spades', 'hearts', 'diamonds', 'clubs')"),
        );
    }

    public function testEnumUnnest(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $client->execute('DROP TYPE IF EXISTS priority');
        $client->execute("CREATE TYPE priority AS ENUM ('low', 'medium', 'high')");

        self::assertSame(
            [
                ['m' => 'low'],
                ['m' => 'medium'],
                ['m' => 'high'],
            ],
            $client->fetchAll(
                "SELECT unnest(['medium'::priority, 'high'::priority, 'low'::priority]) AS m ORDER BY m",
            ),
        );
    }
}
