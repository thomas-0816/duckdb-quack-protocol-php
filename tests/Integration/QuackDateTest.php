<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackDateTest extends TestCase
{
    public function testDateInfinityAndEpoch(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                'negative' => '-infinity',
                'epoch' => '1970-01-01',
                'positive' => 'infinity',
            ]],
            $client->fetchAll(
                "SELECT '-infinity'::DATE AS negative, 'epoch'::DATE AS epoch, 'infinity'::DATE AS positive",
            ),
        );
    }

    public function testDateRange(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                "CAST('2969-01-01' AS date)" => '2969-01-01',
                "CAST('0001-01-01' AS date)" => '0001-01-01',
            ]],
            $client->fetchAll("select '2969-01-01'::date, '0001-01-01'::date"),
        );
    }

    public function testDatetimeRange(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                "CAST('2969-01-01' AS datetime)" => '2969-01-01 00:00:00',
                "CAST('0001-01-01' AS datetime)" => '0001-01-01 00:00:00',
            ]],
            $client->fetchAll("select '2969-01-01'::datetime, '0001-01-01'::datetime"),
        );
    }

    public function testTimestampPrecision(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                "CAST('1992-09-20 11:30:00.123456789' AS TIMESTAMP)" => '1992-09-20 11:30:00.123456',
                "CAST('1992-09-20 11:30:00' AS TIMESTAMP)" => '1992-09-20 11:30:00',
            ]],
            $client->fetchAll(
                "SELECT TIMESTAMP '1992-09-20 11:30:00.123456789', TIMESTAMP '1992-09-20 11:30:00'",
            ),
        );
    }

    public function testTimestampUnits(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                "CAST('1992-09-20 11:30:00.123456789' AS TIMESTAMP_NS)" => '1992-09-20 11:30:00.123456789',
                "CAST('1992-09-20 11:30:00.123456789' AS TIMESTAMP_MS)" => '1992-09-20 11:30:00.123',
                "CAST('1992-09-20 11:30:00.123456789' AS TIMESTAMP_S)" => '1992-09-20 11:30:00',
            ]],
            $client->fetchAll(
                "SELECT TIMESTAMP_NS '1992-09-20 11:30:00.123456789', TIMESTAMP_MS '1992-09-20 11:30:00.123456789', TIMESTAMP_S '1992-09-20 11:30:00.123456789'",
            ),
        );
    }

    public function testTimestampTzInSeptember(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                "CAST('1992-09-20 11:30:00.123456789' AS TIMESTAMPTZ)" => '1992-09-20 09:30:00.123456+00',
                "CAST('1992-09-20 12:30:00.123456789+01:00' AS TIMESTAMPTZ)" => '1992-09-20 11:30:00.123456+00',
                "timezone('America/Denver', CAST('2001-02-16 20:38:40' AS TIMESTAMP))" => '2001-02-17 03:38:40+00',
            ]],
            $client->fetchAll(
                "SELECT TIMESTAMPTZ '1992-09-20 11:30:00.123456789', TIMESTAMPTZ '1992-09-20 12:30:00.123456789+01:00', timezone('America/Denver', TIMESTAMP '2001-02-16 20:38:40')",
            ),
        );
    }

    public function testTimestampTzInMarch(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                "CAST('1992-03-20 11:30:00.123456789' AS TIMESTAMPTZ)" => '1992-03-20 10:30:00.123456+00',
                "CAST('1992-03-20 12:30:00.123456789+01:00' AS TIMESTAMPTZ)" => '1992-03-20 11:30:00.123456+00',
                "timezone('America/Denver', CAST('2001-08-16 20:38:40' AS TIMESTAMP))" => '2001-08-17 02:38:40+00',
            ]],
            $client->fetchAll(
                "SELECT TIMESTAMPTZ '1992-03-20 11:30:00.123456789', TIMESTAMPTZ '1992-03-20 12:30:00.123456789+01:00', timezone('America/Denver', TIMESTAMP '2001-08-16 20:38:40')",
            ),
        );
    }

    public function testTimestampInfinity(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                "CAST('-infinity' AS TIMESTAMP)" => '-infinity',
                "CAST('epoch' AS TIMESTAMP)" => '1970-01-01 00:00:00',
                "CAST('infinity' AS TIMESTAMP)" => 'infinity',
            ]],
            $client->fetchAll(
                "SELECT '-infinity'::TIMESTAMP, 'epoch'::TIMESTAMP, 'infinity'::TIMESTAMP",
            ),
        );
    }

    public function testTime(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [[
                "CAST('1992-09-20 11:30:00.123456' AS TIME)" => '11:30:00.123456',
                "CAST('1992-09-20 11:30:00' AS TIME)" => '11:30:00',
            ]],
            $client->fetchAll(
                "SELECT TIME '1992-09-20 11:30:00.123456', TIME '1992-09-20 11:30:00'",
            ),
        );
    }

    public function testTimeWithTimeZone(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [["CAST('1992-09-20 11:30:00.123456' AS TIMETZ)" => '11:30:00.123456+01']],
            $client->fetchAll("SELECT TIMETZ '1992-09-20 11:30:00.123456'"),
        );
        self::assertSame(
            [["CAST('1992-09-20 11:30:00.123456-02:00' AS TIMETZ)" => '13:30:00.123456+01']],
            $client->fetchAll("SELECT TIMETZ '1992-09-20 11:30:00.123456-02:00'"),
        );
        self::assertSame(
            [["CAST('1992-09-20 11:30:00.123456+05:30' AS TIMETZ)" => '06:00:00.123456+01']],
            $client->fetchAll("SELECT TIMETZ '1992-09-20 11:30:00.123456+05:30'"),
        );
    }

    public function testTimeNs(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='Europe/Berlin'");

        self::assertSame(
            [["CAST('15:30:00.123456789' AS TIME_NS)" => '15:30:00.123456789']],
            $client->fetchAll("SELECT '15:30:00.123456789'::TIME_NS"),
        );
    }

    /**
     * duckdb#28858: VARCHAR/JSON casts are rendered by the server in the
     * session timezone, but raw TIMESTAMPTZ columns stay UTC over Quack.
     */
    public function testTimestampTzFollowsSessionTimezone(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute("SET TimeZone='UTC'");
        self::assertSame(
            [
                '2026-01-23 10:04:56.789123+00',
                '2026-01-23 10:04:56.789123+00',
                '2026-01-23 10:04:56.789123+00',
            ],
            array_values($client->fetchOne(
                "SELECT TIMESTAMPTZ '2026-01-23 12:34:56.789123+02:30', TIMESTAMPTZ '2026-01-23 12:34:56.789123+02:30'::varchar, TIMESTAMPTZ '2026-01-23 12:34:56.789123+02:30'::json") ?? []
            ),
        );

        $client->execute("SET TimeZone='Europe/Berlin'");
        self::assertSame(
            [
                '2026-01-23 10:04:56.789123+00',
                '2026-01-23 11:04:56.789123+01',
                '2026-01-23 11:04:56.789123+01',
            ],
            array_values($client->fetchOne(
                "SELECT TIMESTAMPTZ '2026-01-23 12:34:56.789123+02:30', TIMESTAMPTZ '2026-01-23 12:34:56.789123+02:30'::varchar, TIMESTAMPTZ '2026-01-23 12:34:56.789123+02:30'::json") ?? []
            ),
        );
    }

    public function testTimestampNsInfinity(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone='UTC'");
        $client->execute('create temp table t1 (yr integer not null default year(now()::TIMESTAMP))');

        self::assertSame(
            [
                "CAST('2026-10-16 22:08:43.12345678901' AS TIMESTAMP_NS)" => '2026-10-16 22:08:43.123456789',
                "CAST('infinity' AS TIMESTAMP_NS)" => 'infinity',
                "CAST('-infinity' AS TIMESTAMP_NS)" => '-infinity',
            ],
            $client->fetchOne(
                "select '2026-10-16 22:08:43.12345678901'::TIMESTAMP_NS, 'infinity'::TIMESTAMP_NS, '-infinity'::TIMESTAMP_NS",
            ),
        );
    }
}
