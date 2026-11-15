<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackUnicodeTest extends TestCase
{
    public function testUnicodeInsert(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $client->execute('CREATE TEMP TABLE unicode_test (id INTEGER, latin VARCHAR, mb4 VARCHAR)');
        $client->execute(
            'INSERT INTO unicode_test VALUES (?, ?, ?)',
            [1, 'äöüßÄÖÜ', 'emoji 🐘 test'],
        );

        self::assertSame(
            [
                [
                    'id' => 1,
                    'latin' => 'äöüßÄÖÜ',
                    'mb4' => 'emoji 🐘 test',
                ],
            ],
            $client->fetchAll('SELECT * FROM unicode_test'),
        );
    }

    public function testUnicodeLiterals(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'german' => 'Straße',
                    'city' => 'München',
                    'french' => 'café',
                    'emoji' => '🐘🐋🦀',
                ],
            ],
            $client->fetchAll(
                "SELECT 'Straße' AS german, 'München' AS city, 'café' AS french, '🐘🐋🦀' AS emoji",
            ),
        );
    }

    public function testUnicodeMultipleRows(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $client->execute('CREATE TEMP TABLE unicode_test (id INTEGER, latin VARCHAR, mb4 VARCHAR)');
        $client->execute(
            'INSERT INTO unicode_test VALUES (?, ?, ?)',
            [2, 'café résumé', 'four-byte: 🐘🐋🦀🌍'],
        );
        $client->execute(
            'INSERT INTO unicode_test VALUES (?, ?, ?)',
            [1, 'äöüßÄÖÜ', 'emoji 🐘 test'],
        );

        self::assertSame(
            [
                [
                    'id' => 1,
                    'latin' => 'äöüßÄÖÜ',
                    'mb4' => 'emoji 🐘 test',
                ],
                [
                    'id' => 2,
                    'latin' => 'café résumé',
                    'mb4' => 'four-byte: 🐘🐋🦀🌍',
                ],
            ],
            $client->fetchAll('SELECT * FROM unicode_test ORDER BY id'),
        );
    }

    public function testQuotedUnicode(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $client->execute('CREATE TEMP TABLE unicode_test (c VARCHAR)');

        $value = 'Nice O\'Brian $dollar "quote" \'single quote\' 🐘🐋🦀🌍 öäüß';
        $quoted = "'" . str_replace("'", "''", $value) . "'";

        self::assertSame(
            "'Nice O''Brian \$dollar \"quote\" ''single quote'' 🐘🐋🦀🌍 öäüß'",
            $quoted,
        );

        $client->execute("INSERT INTO unicode_test VALUES ({$quoted})");

        self::assertSame(
            [['c' => 'Nice O\'Brian $dollar "quote" \'single quote\' 🐘🐋🦀🌍 öäüß']],
            $client->fetchAll('SELECT * FROM unicode_test'),
        );
    }
}
