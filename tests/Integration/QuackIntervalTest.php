<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackIntervalTest extends TestCase
{
    public function testIntervalLiterals(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'to_years(CAST(trunc(CAST(1 AS DOUBLE)) AS INTEGER))' => '1 year',
                    'to_years(CAST(trunc(CAST((4 * 10) AS DOUBLE)) AS INTEGER))' => '40 years',
                    'CAST(\'1 month 1 day\' AS INTERVAL)' => '1 month 1 day',
                    'CAST(\'16 months\' AS INTERVAL)' => '1 year 4 months',
                    'CAST(\'48:00:00\' AS INTERVAL)' => '48:00:00',
                ],
            ],
            $client->fetchAll(
                "SELECT INTERVAL 1 YEAR, INTERVAL (4 * 10) YEAR, INTERVAL '1 month 1 day', '16 months'::INTERVAL, '48:00:00'::INTERVAL",
            ),
        );
    }

    public function testIntervalYears(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [['to_years(CAST(trunc(CAST(\'1.5\' AS DOUBLE)) AS INTEGER))' => '1 year']],
            $client->fetchAll("SELECT INTERVAL '1.5' YEARS"),
        );
    }

    public function testIntervalMonthAddition(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [['i' => '2000-01-01 00:00:00'], ['i' => '2000-02-01 00:00:00']],
            $client->fetchAll("SELECT DATE '2000-01-01' + INTERVAL (i) MONTH as i FROM range(2) t(i)"),
        );
    }

    public function testDatePart(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    'datepart(\'decade\', to_years(CAST(trunc(CAST(12 AS DOUBLE)) AS INTEGER)))' => 1,
                    'datepart(\'year\', to_years(CAST(trunc(CAST(12 AS DOUBLE)) AS INTEGER)))' => 12,
                    'datepart(\'second\', to_milliseconds(CAST(1_234 AS DOUBLE)))' => 1,
                    'datepart(\'microsecond\', to_milliseconds(CAST(1_234 AS DOUBLE)))' => 1234000,
                ],
            ],
            $client->fetchAll(
                "SELECT datepart('decade', INTERVAL 12 YEARS), datepart('year', INTERVAL 12 YEARS), datepart('second', INTERVAL 1_234 MILLISECONDS), datepart('microsecond', INTERVAL 1_234 MILLISECONDS)",
            ),
        );
    }

    public function testDateArithmetic(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    '(CAST(\'2000-01-01\' AS DATE) + to_years(CAST(trunc(CAST(1 AS DOUBLE)) AS INTEGER)))' => '2001-01-01 00:00:00',
                    '(CAST(\'2000-01-01 01:33:30\' AS TIMESTAMP) - CAST(\'1 month 13 hours\' AS INTERVAL))' => '1999-11-30 12:33:30',
                    '(CAST(\'02:00:00\' AS TIME) - CAST(\'3 days 23 hours\' AS INTERVAL))' => '03:00:00',
                ],
            ],
            $client->fetchAll(
                "SELECT DATE '2000-01-01' + INTERVAL 1 YEAR, TIMESTAMP '2000-01-01 01:33:30' - INTERVAL '1 month 13 hours', TIME '02:00:00' - INTERVAL '3 days 23 hours'",
            ),
        );
    }

    public function testIntervalDifference(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        self::assertSame(
            [
                [
                    '(CAST(\'2000-02-06 12:00:00\' AS TIMESTAMP) - CAST(\'2000-01-01 11:00:00\' AS TIMESTAMP))' => '36 days 01:00:00',
                    '(CAST(\'2000-02-01\' AS TIMESTAMP) + (CAST(\'2000-02-01\' AS TIMESTAMP) - CAST(\'2000-01-01\' AS TIMESTAMP)))' => '2000-03-03 00:00:00',
                ],
            ],
            $client->fetchAll(
                "SELECT TIMESTAMP '2000-02-06 12:00:00' - TIMESTAMP '2000-01-01 11:00:00', TIMESTAMP '2000-02-01' + (TIMESTAMP '2000-02-01' - TIMESTAMP '2000-01-01')",
            ),
        );
    }
}
