<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackUuidTest extends TestCase
{
    public function testUuid(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $rows = $client->fetchAll('SELECT uuidv4(), uuidv7()');

        self::assertCount(1, $rows);
        self::assertSame(['uuidv4()', 'uuidv7()'], array_keys($rows[0] ?? []));

        foreach ($rows[0] ?? [] as $column => $value) {
            if (!is_string($value)) {
                self::fail("{$column} should be a 36-character UUID string");
            }

            self::assertSame(36, strlen($value), $column);
        }
    }
}
