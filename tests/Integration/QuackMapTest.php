<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackMapTest extends TestCase
{
    public function testMapLiteral(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(\'key1\', \'key2\', \'key3\'), list_value(10, 20, 30))' => [
                        'key1' => 10,
                        'key2' => 20,
                        'key3' => 30,
                    ],
                ],
            ],
            $client->fetchAll("SELECT MAP {'key1': 10, 'key2': 20, 'key3': 30}"),
        );
    }

    public function testMapFromEntries(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    'map_from_entries(list_value("row"(\'key1\', 10), "row"(\'key2\', 20), "row"(\'key3\', 30)))' => [
                        'key1' => 10,
                        'key2' => 20,
                        'key3' => 30,
                    ],
                ],
            ],
            $client->fetchAll("SELECT map_from_entries([('key1', 10), ('key2', 20), ('key3', 30)])"),
        );
    }

    public function testMapFunction(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(\'key1\', \'key2\', \'key3\'), list_value(10, 20, 30))' => [
                        'key1' => 10,
                        'key2' => 20,
                        'key3' => 30,
                    ],
                ],
            ],
            $client->fetchAll("SELECT MAP(['key1', 'key2', 'key3'], [10, 20, 30])"),
        );
    }

    public function testMapNumericKeys(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(1, 5), list_value(42.001, -32.1))' => [
                        1 => 42.001,
                        5 => -32.1,
                    ],
                ],
            ],
            $client->fetchAll('SELECT MAP {1: 42.001, 5: -32.1}'),
        );
    }

    public function testMapListKeys(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(list_value(\'a\', \'b\', \'c\'), list_value(\'c\', \'d\', NULL)), list_value(list_value(1.1, 2.2, NULL), list_value(3.3, 4.4, 5.5)))' => [
                        'a, b, c' => [1.1, 2.2, null],
                        'c, d, ' => [3.3, 4.4, 5.5],
                    ],
                ],
            ],
            $client->fetchAll("SELECT MAP {['a', 'b', 'c']: [1.1, 2.2, null], ['c', 'd', null]: [3.3, 4.4, 5.5]}"),
        );
    }

    public function testMapLookup(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(\'key1\', \'key2\'), list_value(5, 43))[\'key1\']' => 5,
                ],
            ],
            $client->fetchAll("SELECT MAP {'key1': 5, 'key2': 43}['key1']"),
        );
    }

    public function testMapMissingLookup(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(\'key1\', \'key2\'), list_value(5, 43))[\'key3\']' => null,
                ],
            ],
            $client->fetchAll("SELECT MAP {'key1': 5, 'key2': 43}['key3']"),
        );
    }

    public function testMapExtract(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    'map_extract("map"(list_value(\'key1\', \'key2\'), list_value(5, 43)), \'key1\')' => [5],
                ],
            ],
            $client->fetchAll("SELECT map_extract(MAP {'key1': 5, 'key2': 43}, 'key1')"),
        );
    }

    public function testMapListKey(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(list_value(\'key1a\', \'key1b\')), list_value(42))' => [
                        'key1a, key1b' => 42,
                    ],
                ],
            ],
            $client->fetchAll("select MAP {['key1a', 'key1b']: 42}"),
        );
    }

    public function testMapNestedListKey(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(list_value(list_value(\'key1a\', \'key1b\'), list_value(\'key1c\', \'key1d\'))), list_value(42))' => [
                        'key1a, key1b, key1c, key1d' => 42,
                    ],
                ],
            ],
            $client->fetchAll("select MAP {[['key1a', 'key1b'], ['key1c', 'key1d']]: 42}"),
        );
    }

    public function testMapListKeyAndValue(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(list_value(\'key1a\', \'key1b\')), list_value(list_value(42, 21)))' => [
                        'key1a, key1b' => [42, 21],
                    ],
                ],
            ],
            $client->fetchAll("select MAP {['key1a', 'key1b']: [42, 21]}"),
        );
    }

    public function testMapNullListKey(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [
                [
                    '"map"(list_value(list_value(NULL)), list_value(5))' => [
                        '' => 5,
                    ],
                ],
            ],
            $client->fetchAll('select MAP {[null]: 5}'),
        );
    }

    public function testMapNulBytes(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            '[{"\"map\"(list_value(((\'a\' || chr(0)) || \'b\')), list_value(((\'c\' || chr(0)) || \'d\')))":{"a\u0000b":"c\u0000d"}}]',
            json_encode($client->fetchAll("SELECT MAP(['a'||chr(0)||'b'], ['c'||chr(0)||'d'])")),
        );
    }
}
