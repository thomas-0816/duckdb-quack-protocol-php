<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackListTest extends TestCase
{
    public function testIntegerList(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [['list_value(1, 2, 3)' => [1, 2, 3]]],
            $client->fetchAll('SELECT [1, 2, 3]'),
        );
    }

    public function testStringListWithNull(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    "list_value('duck', 'goose', NULL, 'heron')" => [
                        'duck',
                        'goose',
                        null,
                        'heron',
                    ],
                ],
            ],
            $client->fetchAll("SELECT ['duck', 'goose', NULL, 'heron']"),
        );
    }

    public function testNestedLists(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    "list_value(list_value('duck', 'goose', 'heron'), NULL, list_value('frog', 'toad'), list_value())" => [
                        ['duck', 'goose', 'heron'],
                        null,
                        ['frog', 'toad'],
                        [],
                    ],
                ],
            ],
            $client->fetchAll("SELECT [['duck', 'goose', 'heron'], NULL, ['frog', 'toad'], []]"),
        );
    }

    public function testListValue(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [['list_value(1, 2, 3)' => [1, 2, 3]]],
            $client->fetchAll('SELECT list_value(1, 2, 3)'),
        );
    }

    public function testListSlice(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [["list_value('a', 'b', 'c')[1:2]" => ['a', 'b']]],
            $client->fetchAll("SELECT ['a', 'b', 'c'][1:2]"),
        );
    }

    public function testListSliceFunction(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [["list_slice(list_value('a', 'b', 'c'), 2, 3)" => ['b', 'c']]],
            $client->fetchAll("SELECT list_slice(['a', 'b', 'c'], 2, 3)"),
        );
    }
}
