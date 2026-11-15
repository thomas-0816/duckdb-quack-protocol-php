<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackStringTest extends TestCase
{
    public function testConcatWithChr(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame([['msg' => 'Hello world']], $client->fetchAll("SELECT 'Hello' || chr(32) || 'world' AS msg"));
    }

    public function testMultilineLiteral(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame('Hello World', $client->fetchValue("SELECT 'Hello'\n' '\n'World' AS greeting"));
    }

    public function testConcatOperator(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame([['greeting' => 'Hello World']], $client->fetchAll("SELECT 'Hello' || ' ' || 'World' AS greeting"));
    }

    public function testInvalidAdjacentStrings(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        try {
            $client->fetchAll("SELECT 'Hello' ' ' 'World' AS greeting");
            self::fail('expected adjacent string literals to fail');
        } catch (ServerException $e) {
            self::assertStringContainsString("syntax error at or near \"' '\"", $e->getMessage());
        }
    }

    public function testEscapeString(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame([['msg' => "Hello\nworld"]], $client->fetchAll('SELECT e\'Hello\nworld\' AS msg'));
    }

    public function testDollarQuoted(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame([['msg' => 'Hello world']], $client->fetchAll('SELECT $$Hello world$$ AS msg'));
    }

    public function testDollarQuotedPrice(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [['msg' => 'The price is $9.95']],
            $client->fetchAll('SELECT $$The price is $9.95$$ AS msg'),
        );
    }

    public function testTaggedDollarQuoted(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [['msg' => ' this string can contain newlines \'single quotes\', "double quotes", and $$dollar quotes$$ ']],
            $client->fetchAll(
                'SELECT $tag$ this string can contain newlines \'single quotes\', "double quotes", and $$dollar quotes$$ $tag$ AS msg',
            ),
        );
    }
}
