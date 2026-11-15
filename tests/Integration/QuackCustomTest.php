<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackCustomTest extends TestCase
{
    public function testInetValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('INSTALL inet; LOAD inet;');

        self::assertSame(
            [
                [
                    "CAST('127.0.0.1' AS INET)" => '127.0.0.1',
                    "CAST('2001:db8:3c4d::/48' AS INET)" => '2001:db8:3c4d::/48',
                    "CAST('10.0.0.0/8' AS INET)" => '10.0.0.0/8',
                    "host(CAST('192.168.1.5' AS INET))" => '192.168.1.5',
                    '"family"(CAST(\'2001:db8::1\' AS INET))' => 6,
                    'contains' => true,
                ],
            ],
            $client->fetchAll(
                "SELECT '127.0.0.1'::INET, '2001:db8:3c4d::/48'::INET, '10.0.0.0/8'::INET,
                   host('192.168.1.5'::INET), family('2001:db8::1'::INET),
                   ('192.168.0.0/24'::INET <<= '192.168.0.0/16'::INET) AS contains",
            ),
        );
    }

    public function testNullInet(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('INSTALL inet; LOAD inet;');

        self::assertSame(
            [['CAST(NULL AS INET)' => null]],
            $client->fetchAll('SELECT NULL::INET'),
        );
    }
}
