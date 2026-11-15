<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackVariantTest extends TestCase
{
    public function testVariantScalarAndCollectionValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE variant_values (v VARIANT)');
        $client->execute(
            'INSERT INTO variant_values VALUES (?), (?::integer), (?::decimal), (?), (?::integer[]), (?::varchar[]), (?::json)',
            ['hello', 42, 42.21, null, [1, 2], ['foo', 'bar'], ['foo' => 'bar']],
        );

        self::assertSame(
            [['v' => 'hello'], ['v' => 42], ['v' => 42.21], ['v' => null], ['v' => [1, 2]], ['v' => ['foo', 'bar']], ['v' => ['foo' => 'bar']]],
            $client->fetchAll('SELECT * FROM variant_values'),
        );
    }

    public function testVariantExtendedValues(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone = 'Europe/Berlin'");
        $client->beginTransaction();
        $client->execute('CREATE TEMP TABLE variant_values (v VARIANT)');
        $client->execute("CREATE TYPE mood2 AS ENUM ('sad', 'ok', 'happy')");

        try {
            $client->execute(
                'INSERT INTO variant_values VALUES (?), (?::integer), (?::decimal), (?), (?::integer[]), (?::json::variant), (?::json), (?::hugeint), (?::bignum), (?::bigint), (?::bigint)',
                ['hello', 42, 42.21, null, [1, 2], ['foo', 'bar', true, null], ['foo' => 'bar'], 9223372036854775807, '340282366920938463463374607431768211455', PHP_INT_MAX, -PHP_INT_MAX],
            );
            $client->execute(
                "INSERT INTO variant_values VALUES (1/0), (-1/0), (0/0), ('101010'::BIT), ('2969-01-01'::date), (INTERVAL 1 YEAR), (true), (uuidv4()), ('sad'::mood2), (union_value(str := 'three')), ('\\xAA\\xAB\\xAC'::BLOB)",
            );
            $client->execute(
                "INSERT INTO variant_values VALUES (MAP {'key1': 10}), ('[1, null, {\"key\": \"value\"}]'::JSON), ({'key1': 'value1'})",
            );
            $client->execute(
                "INSERT INTO variant_values VALUES (TIMESTAMP_NS '1992-09-20 11:30:00.123456789'), (TIMESTAMP_MS '1992-09-20 11:30:00.123456789'), (TIMESTAMP_S '1992-09-20 11:30:00.123456789')",
            );
            $client->execute(
                "INSERT INTO variant_values VALUES (TIMESTAMPTZ '1992-09-20 11:30:00.123456789'), (TIMESTAMPTZ '1992-09-20 12:30:00.123456789+01:00'), (timezone('America/Denver', TIMESTAMP '2001-02-16 20:38:40'))",
            );

            $rows = $client->fetchAll('SELECT * FROM variant_values');
            self::assertCount(31, $rows);
            self::assertIsString($rows[18]['v']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $rows[18]['v']);
            self::assertIsFloat($rows[13]['v']);
            self::assertNan($rows[13]['v']);
            $rows[13]['v'] = '<nan>';
            $rows[18]['v'] = '<uuid>';

            self::assertSame([
                ['v' => 'hello'], ['v' => 42], ['v' => 42.21], ['v' => null],
                ['v' => [1, 2]],
                ['v' => ['foo', 'bar', true, null]],
                ['v' => ['foo' => 'bar']],
                ['v' => 9223372036854775807],
                ['v' => '340282366920938463463374607431768211455'],
                ['v' => 9223372036854775807],
                ['v' => -9223372036854775807],
                ['v' => INF],
                ['v' => -INF],
                ['v' => '<nan>'],
                ['v' => '101010'],
                ['v' => '2969-01-01'],
                ['v' => '1 year'],
                ['v' => true],
                ['v' => '<uuid>'],
                ['v' => 'sad'],
                ['v' => 'three'],
                ['v' => "\xAA\xAB\xAC"],
                ['v' => [['key' => 'key1', 'value' => 10]]],
                ['v' => [1, null, ['key' => 'value']]],
                ['v' => ['key1' => 'value1']],
                ['v' => '1992-09-20 11:30:00.123456789'],
                ['v' => '1992-09-20 11:30:00.123'],
                ['v' => '1992-09-20 11:30:00'],
                ['v' => '1992-09-20 09:30:00.123456+00'], // TODO fix string(29) "1992-09-20 11:30:00.123456+02"
                ['v' => '1992-09-20 11:30:00.123456+00'], // TODO fix string(29) "1992-09-20 13:30:00.123456+02"
                ['v' => '2001-02-17 03:38:40+00']], // TODO fix string(22) "2001-02-17 04:38:40+01"
                $rows,
            );
        } finally {
            $client->execute("DROP TYPE mood2");
            $client->rollback();
        }
    }

    public function testVariantTimestampsInUtc(): void
    {
        date_default_timezone_set('UTC');

        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone = 'UTC'");
        $client->execute('CREATE TEMP TABLE variant_values (v2 VARIANT)');
        $client->execute(
            "INSERT INTO variant_values VALUES (TIMESTAMPTZ '1992-09-20 11:30:00.123456789'), (TIMESTAMPTZ '1992-09-20 12:30:00.123456789+01:00'), (timezone('America/Denver', TIMESTAMP '2001-02-16 20:38:40'))",
        );

        self::assertSame(
            [
                ['v2' => '1992-09-20 11:30:00.123456+00'],
                ['v2' => '1992-09-20 11:30:00.123456+00'],
                ['v2' => '2001-02-17 03:38:40+00'],
            ],
            $client->fetchAll('SELECT * FROM variant_values'),
        );
    }

    public function testVariantTimestampsUseUtcRegardlessOfSessionTimezone(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute("SET TimeZone = 'Europe/Berlin'");
        $client->execute('CREATE TEMP TABLE variant_values (v3 VARIANT)');
        $client->execute("INSERT INTO variant_values VALUES (TIMESTAMPTZ '1992-09-20 11:30:00.123456789'), (TIMESTAMPTZ '1992-09-20 12:30:00.123456789+01:00'), (timezone('America/Denver', TIMESTAMP '2001-02-16 20:38:40'))");

        // PDO renders these values in the session timezone (+02 and +01).
        // Quack always renders TIMESTAMPTZ variants in UTC.
        self::assertSame(
            [
                ['v3' => '1992-09-20 09:30:00.123456+00'], // TODO fix string(29) "1992-09-20 11:30:00.123456+02"
                ['v3' => '1992-09-20 11:30:00.123456+00'], // TODO fix string(29) "1992-09-20 13:30:00.123456+02"
                ['v3' => '2001-02-17 03:38:40+00'], // TODO fix string(22) "2001-02-17 04:38:40+01"
            ],
            $client->fetchAll('SELECT * FROM variant_values'),
        );
    }

    public function testVariantInsideStruct(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE variant_structs (s STRUCT(v VARIANT))');
        $client->execute('INSERT INTO variant_structs VALUES (?)', [['v' => 'hello']]);
        $client->execute("INSERT INTO variant_structs VALUES ({'v': MAP {'key1': 10}}), ({'v': {'key1': 'value1'}})");

        self::assertSame([
            ['s' => ['v' => 'hello']],
            ['s' => ['v' => [['key' => 'key1', 'value' => 10]]]],
            ['s' => ['v' => ['key1' => 'value1']]]],
            $client->fetchAll('SELECT * FROM variant_structs'),
        );
    }

    public function testVariantStringifiedJson(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE variant_values (v VARIANT)');
        $client->execute("INSERT INTO variant_values VALUES ('[1, 2, 3]')");

        self::assertSame([['v' => '[1, 2, 3]']], $client->fetchAll('SELECT * FROM variant_values'));
    }
}
