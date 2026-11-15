<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Messages\PrepareResponseMessage;
use DuckDB\Quack\QuackClient;
use DuckDB\Quack\Types\Info\ListTypeInfo;
use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\LogicalTypeId;
use PHPUnit\Framework\TestCase;

final class QuackNullTest extends TestCase
{
    public function testNullColumnTypesAndValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $sql = <<<'SQL'
            SELECT
              null, null::boolean, null::tinyint, null::smallint, null::integer, null::bigint, null::utinyint, null::usmallint,
              null::uinteger, null::ubigint, null::float, null::double, null::timestamp, null::date, null::time, null::interval,
              null::hugeint, null::uhugeint, null::varchar, null::blob, null::decimal, null::timestamp_s, null::timestamp_ms,
              null::timestamp_ns, null::enum('a'), null::struct(duck integer), null::integer[], null::integer[1], map_from_entries(null),
              null::union(str varchar), null::uuid, null::bit, null::timetz, null::timestamptz, null::timestamptz_ns, null::time_ns, null::geometry,
              null::variant, null::bignum, null::struct, row(), null::map(int, int), NULL::TYPE
            SQL;

        $result = $client->fetchAll($sql);

        self::assertSame(
            [
                [
                    'NULL' => null,
                    'CAST(NULL AS BOOLEAN)' => null,
                    'CAST(NULL AS tinyint)' => null,
                    'CAST(NULL AS SMALLINT)' => null,
                    'CAST(NULL AS INTEGER)' => null,
                    'CAST(NULL AS BIGINT)' => null,
                    'CAST(NULL AS utinyint)' => null,
                    'CAST(NULL AS usmallint)' => null,
                    'CAST(NULL AS uinteger)' => null,
                    'CAST(NULL AS ubigint)' => null,
                    'CAST(NULL AS FLOAT)' => null,
                    'CAST(NULL AS double)' => null,
                    'CAST(NULL AS TIMESTAMP)' => null,
                    'CAST(NULL AS date)' => null,
                    'CAST(NULL AS TIME)' => null,
                    'CAST(NULL AS INTERVAL)' => null,
                    'CAST(NULL AS hugeint)' => null,
                    'CAST(NULL AS uhugeint)' => null,
                    'CAST(NULL AS VARCHAR)' => null,
                    'CAST(NULL AS blob)' => null,
                    'CAST(NULL AS DECIMAL)' => null,
                    'CAST(NULL AS timestamp_s)' => null,
                    'CAST(NULL AS timestamp_ms)' => null,
                    'CAST(NULL AS timestamp_ns)' => null,
                    "CAST(NULL AS enum('a'))" => null,
                    'CAST(NULL AS STRUCT(duck INTEGER))' => null,
                    'CAST(NULL AS INTEGER[])' => null,
                    'CAST(NULL AS INTEGER[1])' => null,
                    'map_from_entries(NULL)' => null,
                    'CAST(NULL AS UNION(str VARCHAR))' => null,
                    'CAST(NULL AS uuid)' => null,
                    'CAST(NULL AS BIT)' => null,
                    'CAST(NULL AS timetz)' => null,
                    'CAST(NULL AS timestamptz)' => null,
                    'CAST(NULL AS timestamptz_ns)' => null,
                    'CAST(NULL AS time_ns)' => null,
                    'CAST(NULL AS GEOMETRY)' => null,
                    'CAST(NULL AS VARIANT)' => null,
                    'CAST(NULL AS bignum)' => null,
                    'CAST(NULL AS STRUCT)' => null,
                    '"row"()' => [],
                    'CAST(NULL AS MAP(INTEGER, INTEGER))' => null,
                    'CAST(NULL AS TYPE)' => null,
                ],
            ],
            $result,
        );
    }

    public function testNullUnion(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $sql = 'select null::varchar union select null::integer[] union select null::struct(i1 integer)';
        self::assertSame(
            [['CAST(NULL AS VARCHAR)' => null]],
            $client->fetchAll($sql),
        );
    }

    public function testNullJson(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);

        $sql = "select 'null'::JSON, NULL::JSON";
        self::assertSame(
            [
                [
                    'CAST(\'null\' AS "JSON")' => null,
                    'CAST(NULL AS "JSON")' => null,
                ],
            ],
            $client->fetchAll($sql),
        );
    }
}
