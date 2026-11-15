<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackBulkInsertTest extends TestCase
{
    public function testAssociativeRows(): void
    {
        $data = [];
        for ($i = 0; $i < 100_000; $i++) {
            $data[] = ['i1' => $i, 'v1' => 'foo' . $i];
        }

        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE literal_rows (i1 integer, v1 varchar)');
        $client->execute("INSERT INTO literal_rows SELECT value->>'i1', value->>'v1' FROM json_each('" . json_encode($data) . "')");
        self::assertSame(100_000, $client->fetchValue('SELECT count(*) FROM literal_rows'));

        $client->execute('CREATE TEMP TABLE parameter_rows (i1 integer, v1 varchar)');
        $client->execute("INSERT INTO parameter_rows SELECT value->>'i1', value->>'v1' FROM json_each(?)", [$data]);
        self::assertSame(100_000, $client->fetchValue('SELECT count(*) FROM parameter_rows'));
    }

    public function testListRows(): void
    {
        $data = [];
        for ($i = 0; $i < 100_000; $i++) {
            $data[] = [$i, 'foo' . $i];
        }

        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE literal_rows (i1 integer, v1 varchar)');
        $client->execute("INSERT INTO literal_rows SELECT value->>0, value->>1 FROM json_each('" . json_encode($data) . "')");
        self::assertSame(100_000, $client->fetchValue('SELECT count(*) FROM literal_rows'));

        $client->execute('CREATE TEMP TABLE parameter_rows (i1 integer, v1 varchar)');
        $client->execute('INSERT INTO parameter_rows SELECT value->>0, value->>1 FROM json_each(?)', [$data]);
        self::assertSame(100_000, $client->fetchValue('SELECT count(*) FROM parameter_rows'));
    }
}
