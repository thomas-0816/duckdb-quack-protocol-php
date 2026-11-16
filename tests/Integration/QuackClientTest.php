<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackClientTest extends TestCase
{
    public function testConnectSsl(): void
    {
        $client = QuackClient::connect('https://localhost:9495', 'secret', 3, 10, [], self::sslCertificate());
        self::assertSame(3, $client->getConnection()->quackVersion());
    }

    public function testConnect(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        self::assertSame(3, $client->getConnection()->quackVersion());

        self::assertSame(
            [
                ['id' => 1, 'label' => 'one'],
                ['id' => 2, 'label' => 'two'],
            ],
            $client->fetchAll(
                "SELECT * FROM (VALUES (1::INTEGER, 'one'::VARCHAR), (2, 'two')) AS t(id, label) ORDER BY id",
            ),
        );

        $row = $client->fetchOne('SELECT ? + 1 AS answer, ? AS label, ?::INTEGER[] AS list_value', [41, "can't", [1, 2, 3]]) ?? self::fail('expected a row');
        self::assertSame(42, $row['answer']);
        self::assertSame("can't", $row['label']);
        self::assertSame([1, 2, 3], $row['list_value']);

        $row = $client->fetchOne(
            "SELECT MAP {'a': 1} AS m, "
            . "CAST('happy' AS ENUM ('sad', 'happy')) AS e, "
            . 'CAST(42 AS UNION(answer INTEGER)) AS u',
        ) ?? self::fail('expected a row');
        self::assertSame(
            ['a' => 1],
            $row['m'],
        );
        self::assertSame('happy', $row['e']);
        self::assertSame(
            ['answer' => 42],
            $row['u'],
        );

        self::assertSame(
            9_223_372_036_854_775_807,
            $client->fetchValue('SELECT 9223372036854775807::BIGINT AS n'),
        );
        self::assertSame(
            '170141183460469231731687303715884105727',
            $client->fetchValue(
                'SELECT 170141183460469231731687303715884105727::HUGEINT AS n',
            ),
        );

        $temporal = $client->fetchOne(
            "SELECT DATE '1970-01-02' AS d, "
            . "TIME '12:34:56.5' AS t, "
            . "TIMESTAMP '1970-01-01 00:00:01.000001' AS ts, "
            . "INTERVAL '1 year 2 months 3 days' AS i",
        ) ?? self::fail('expected a row');
        self::assertSame('1970-01-02', $temporal['d']);
        self::assertSame('12:34:56.5', $temporal['t']);
        self::assertSame('1970-01-01 00:00:01.000001', $temporal['ts']);
        self::assertSame('1 year 2 months 3 days', $temporal['i']);

    }

    public function testResultFetchHelpers(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['n' => 1], ['n' => 2], ['n' => 3]],
            $client->fetchAll('SELECT * FROM (VALUES (1), (2), (3)) t(n)'),
        );

        self::assertSame(
            ['n' => 1],
            $client->fetchOne('SELECT * FROM (VALUES (1), (2)) t(n)'),
        );

        self::assertSame(
            [10, 20],
            $client->fetchColumn('SELECT * FROM (VALUES (10), (20)) t(n)'),
        );
        self::assertSame(
            ['a', 'b'],
            $client->fetchColumn(
                "SELECT label FROM (VALUES (1, 'a'), (2, 'b')) t(n, label)",
            ),
        );
        self::assertSame(42, $client->fetchValue('SELECT 42 AS n'));
        $row = $client->fetchOne(
            "SELECT * FROM (VALUES (1, 'a'), (2, 'b')) t(n, label)",
        ) ?? self::fail('expected a row');
        self::assertSame('a', $row['label']);
        self::assertNull($client->fetchValue('SELECT 1 AS n WHERE false'));

        self::assertSame(
            [['n' => 1], ['n' => 2]],
            $client->fetchAll('SELECT * FROM (VALUES (1), (2)) t(n)'),
        );
    }

    public function testFetchIterator(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['n' => 1], ['n' => 2], ['n' => 3]],
            iterator_to_array(
                $client->fetchIterator('SELECT * FROM (VALUES (1), (2), (3)) t(n)'),
            ),
        );

        // Breaking out of a temporary iterator releases it immediately.
        foreach (
            $client->fetchIterator('SELECT * FROM (VALUES (1), (2), (3)) t(n)') as $row
        ) {
            self::assertSame(['n' => 1], $row);
            break;
        }
        self::assertSame(['n' => 1], $client->fetchOne('SELECT 1 AS n'));

        // Creating an iterator without starting it does not execute anything.
        $notStarted = $client->fetchIterator('SELECT 1 AS n');
        unset($notStarted);

        // A generator can be consumed incrementally.
        $generator = $client->fetchIterator('SELECT * FROM (VALUES (1), (2), (3)) t(n)');
        self::assertSame(['n' => 1], $generator->current());
        $generator->next();
        self::assertSame(['n' => 2], $generator->current());

        unset($generator);
        self::assertSame(['n' => 1], $client->fetchOne('SELECT 1 AS n'));
    }

    public function testExecuteReturnsChangedRows(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            0,
            $client->execute('CREATE TEMP TABLE execute_counts(id INTEGER)'),
        );
        self::assertSame(
            4,
            $client->execute('INSERT INTO execute_counts VALUES (1), (2), (3), (4)'),
        );
        self::assertSame(
            2,
            $client->execute('UPDATE execute_counts SET id = id + 10 WHERE id <= 2'),
        );
        self::assertSame(
            1,
            $client->execute('DELETE FROM execute_counts WHERE id = 12'),
        );
        self::assertSame(
            [3, 4, 11],
            $client->fetchColumn('SELECT id FROM execute_counts ORDER BY id'),
        );
    }

    public function testTransactions(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE transaction_rows(id INTEGER)');
        $client->beginTransaction();
        self::assertSame(
            2,
            $client->execute('INSERT INTO transaction_rows VALUES (1), (2)'),
        );
        $client->commit();

        self::assertSame(
            [1, 2],
            $client->fetchColumn('SELECT id FROM transaction_rows ORDER BY id'),
        );
    }

    public function testRollbackDiscardsChanges(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE transaction_discard(id INTEGER)');
        $client->beginTransaction();
        $client->execute('INSERT INTO transaction_discard VALUES (1), (2)');
        $client->rollback();

        self::assertSame([], $client->fetchColumn('SELECT id FROM transaction_discard'));
    }

    public function testTransactionCallbackCommitsOnSuccess(): void
    {
        $client = QuackClient::connect( 'http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute('CREATE TEMP TABLE transaction_callback(id INTEGER)');
        $result = $client->transaction(function (QuackClient $client): string {
            $client->execute('INSERT INTO transaction_callback VALUES (1), (2)');

            return 'committed';
        });

        self::assertSame('committed', $result);
        self::assertSame(
            [1, 2],
            $client->fetchColumn('SELECT id FROM transaction_callback ORDER BY id'),
        );
    }

    public function testTransactionCallbackRollsBackOnFailure(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE transaction_rollback(id INTEGER)');
        try {
            $client->transaction(function (QuackClient $client): void {
                $client->execute('INSERT INTO transaction_rollback VALUES (1)');
                throw new \RuntimeException('callback failed');
            });
            self::fail('expected the callback failure to propagate');
        } catch (\RuntimeException $e) {
            self::assertSame('callback failed', $e->getMessage());
        }

        self::assertSame(
            [],
            $client->fetchColumn('SELECT id FROM transaction_rollback'),
        );
    }

    public function testBooleanType(): void
    {
        self::assertTrue($this->queryValue('SELECT TRUE::BOOLEAN AS v'));
        self::assertFalse($this->queryValue('SELECT FALSE::BOOLEAN AS v'));
        self::assertNull($this->queryValue('SELECT NULL::BOOLEAN AS v'));
    }

    public function testTinyIntType(): void
    {
        self::assertSame(-128, $this->queryValue('SELECT (-128)::TINYINT AS v'));
        self::assertSame(127, $this->queryValue('SELECT 127::TINYINT AS v'));
    }

    public function testSmallIntType(): void
    {
        self::assertSame(-32_768, $this->queryValue('SELECT (-32768)::SMALLINT AS v'));
        self::assertSame(32_767, $this->queryValue('SELECT 32767::SMALLINT AS v'));
    }

    public function testIntegerType(): void
    {
        self::assertSame(
            -2_147_483_648,
            $this->queryValue('SELECT (-2147483648)::INTEGER AS v'),
        );
        self::assertSame(
            2_147_483_647,
            $this->queryValue('SELECT 2147483647::INTEGER AS v'),
        );
    }

    public function testBigIntType(): void
    {
        self::assertSame(
            PHP_INT_MIN,
            $this->queryValue('SELECT (-9223372036854775808)::BIGINT AS v'),
        );
        self::assertSame(
            PHP_INT_MAX,
            $this->queryValue('SELECT 9223372036854775807::BIGINT AS v'),
        );
    }

    public function testUTinyIntType(): void
    {
        self::assertSame(255, $this->queryValue('SELECT 255::UTINYINT AS v'));
    }

    public function testUSmallIntType(): void
    {
        self::assertSame(65_535, $this->queryValue('SELECT 65535::USMALLINT AS v'));
    }

    public function testUIntegerType(): void
    {
        self::assertSame(
            4_294_967_295,
            $this->queryValue('SELECT 4294967295::UINTEGER AS v'),
        );
    }

    public function testUBigIntType(): void
    {
        self::assertSame(
            '18446744073709551615',
            $this->queryValue('SELECT 18446744073709551615::UBIGINT AS v'),
        );
    }

    public function testHugeIntType(): void
    {
        self::assertSame(
            '170141183460469231731687303715884105727',
            $this->queryValue('SELECT 170141183460469231731687303715884105727::HUGEINT AS v'),
        );
        self::assertSame(
            '-170141183460469231731687303715884105728',
            $this->queryValue('SELECT (-170141183460469231731687303715884105728)::HUGEINT AS v'),
        );
    }

    public function testUHugeIntType(): void
    {
        self::assertSame(
            '340282366920938463463374607431768211455',
            $this->queryValue('SELECT 340282366920938463463374607431768211455::UHUGEINT AS v'),
        );
    }

    public function testFloatType(): void
    {
        self::assertSame(1.5, $this->queryValue('SELECT 1.5::FLOAT AS v'));
        self::assertSame(-0.25, $this->queryValue('SELECT (-0.25)::FLOAT AS v'));
    }

    public function testDoubleType(): void
    {
        self::assertSame(2.25, $this->queryValue('SELECT 2.25::DOUBLE AS v'));
    }

    public function testDecimalType(): void
    {
        self::assertSame(
            12.34,
            $this->queryValue("SELECT CAST('12.34' AS DECIMAL(10,2)) AS v"),
        );
        self::assertSame(
            '12345678901234567890.1234567890',
            $this->queryValue("SELECT CAST('12345678901234567890.1234567890' AS DECIMAL(38,10)) AS v"),
        );
    }

    public function testCharType(): void
    {
        self::assertSame('abc', $this->queryValue("SELECT 'abc'::CHAR AS v"));
        self::assertSame('abc', $this->queryValue("SELECT 'abc'::CHAR(5) AS v"));
    }

    public function testVarcharType(): void
    {
        self::assertSame('hello', $this->queryValue("SELECT 'hello'::VARCHAR AS v"));
        self::assertSame(
            "can't",
            $this->queryValue("SELECT 'can''t'::VARCHAR AS v"),
        );
        self::assertSame(
            '🦆',
            $this->queryValue("SELECT '🦆'::VARCHAR AS v"),
        );
        self::assertSame(
            'DuckDB 🦆 データ',
            $this->queryValue("SELECT 'DuckDB 🦆 データ'::VARCHAR AS v"),
        );
    }

    public function testBlobType(): void
    {
        self::assertSame('hi', $this->queryValue("SELECT 'hi'::BLOB AS v"));
        self::assertSame(
            "\x00\x01\xff",
            $this->queryValue("SELECT '\\x00\\x01\\xFF'::BLOB AS v"),
        );
    }

    public function testUuidType(): void
    {
        $uuid = '00000000-0000-0000-0000-000000000001';

        self::assertSame(
            $uuid,
            $this->queryValue("SELECT '$uuid'::UUID AS v"),
        );
    }

    public function testDateType(): void
    {
        self::assertSame(
            '1970-01-02',
            $this->queryValue("SELECT DATE '1970-01-02' AS v"),
        );
        self::assertSame(
            '1969-12-31',
            $this->queryValue("SELECT DATE '1969-12-31' AS v"),
        );
    }

    public function testTimeType(): void
    {
        self::assertSame(
            '12:34:56.5',
            $this->queryValue("SELECT TIME '12:34:56.5' AS v"),
        );
    }

    public function testTimeNsType(): void
    {
        self::assertSame(
            '12:34:56.123456789',
            $this->queryValue("SELECT TIME_NS '12:34:56.123456789' AS v"),
        );
    }

    public function testTimeTzType(): void
    {
        self::assertSame(
            '12:34:56+01:00',
            $this->queryValue("SELECT TIMETZ '12:34:56+01:00' AS v"),
        );
        self::assertSame(
            '12:34:56-05:30',
            $this->queryValue("SELECT TIMETZ '12:34:56-05:30' AS v"),
        );
    }

    public function testTimestampType(): void
    {
        self::assertSame(
            '1970-01-01 00:00:01.5',
            $this->queryValue("SELECT TIMESTAMP '1970-01-01 00:00:01.5' AS v"),
        );
        self::assertSame(
            '1969-12-31 23:59:59.999999',
            $this->queryValue("SELECT TIMESTAMP '1969-12-31 23:59:59.999999' AS v"),
        );
    }

    public function testTimestampSecondsType(): void
    {
        self::assertSame(
            '1970-01-01 00:00:01',
            $this->queryValue("SELECT TIMESTAMP_S '1970-01-01 00:00:01' AS v"),
        );
    }

    public function testTimestampMillisType(): void
    {
        self::assertSame(
            '1970-01-01 00:00:01.5',
            $this->queryValue("SELECT TIMESTAMP_MS '1970-01-01 00:00:01.5' AS v"),
        );
    }

    public function testTimestampNanosType(): void
    {
        self::assertSame(
            '1970-01-01 00:00:01.123456789',
            $this->queryValue("SELECT TIMESTAMP_NS '1970-01-01 00:00:01.123456789' AS v"),
        );
    }

    public function testTimestampTzType(): void
    {
        self::assertSame(
            '1970-01-01 00:00:01+00',
            $this->queryValue("SELECT TIMESTAMPTZ '1970-01-01 00:00:01+00' AS v", ['timezone' => 'UTC']),
        );
    }

    public function testConnectConfigAppliesSessionSettings(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10, ['timezone' => 'Europe/Berlin']);

        self::assertSame(
            'Europe/Berlin',
            $client->fetchValue("SELECT current_setting('TimeZone') AS tz"),
        );
    }

    public function testTimestampTzUsesConfiguredTimezone(): void
    {
        self::assertSame(
            '1970-01-01 00:00:01+00',
            $this->queryValue("SELECT TIMESTAMPTZ '1970-01-01 00:00:01+00' AS v", ['timezone' => 'Europe/Berlin']),
        );
    }

    public function testConfiguredTimezonesDoNotLeakBetweenClients(): void
    {
        $berlin = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10, ['timezone' => 'Europe/Berlin']);
        $utc = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10, ['timezone' => 'UTC']);
        $sql = "SELECT TIMESTAMPTZ '1970-01-01 00:00:01+00' AS v";

        self::assertSame('1970-01-01 00:00:01+00', $berlin->fetchValue($sql));
        self::assertSame('1970-01-01 00:00:01+00', $utc->fetchValue($sql));
        self::assertSame('1970-01-01 00:00:01+00', $berlin->fetchValue($sql));
    }

    public function testIntervalType(): void
    {
        self::assertSame(
            '1 year 2 months 3 days 04:05:06.789',
            $this->queryValue(
                "SELECT INTERVAL '1 year 2 months 3 days 04:05:06.789' AS v",
            ),
        );
        self::assertSame(
            '00:00:00',
            $this->queryValue("SELECT INTERVAL '0' AS v"),
        );
    }

    public function testBitType(): void
    {
        self::assertSame('1010', $this->queryValue("SELECT '1010'::BIT AS v"));
        self::assertSame('1', $this->queryValue("SELECT '1'::BIT AS v"));
        self::assertSame(
            '11111111',
            $this->queryValue("SELECT '11111111'::BIT AS v"),
        );
    }

    public function testBigNumType(): void
    {
        self::assertSame(
            '170141183460469231731687303715884105727',
            $this->queryValue(
                'SELECT 170141183460469231731687303715884105727::BIGNUM AS v',
            ),
        );
        self::assertSame(
            '-170141183460469231731687303715884105727',
            $this->queryValue(
                'SELECT (-170141183460469231731687303715884105727)::BIGNUM AS v',
            ),
        );
    }

    public function testEnumType(): void
    {
        self::assertSame(
            'happy',
            $this->queryValue(
                "SELECT CAST('happy' AS ENUM ('sad', 'ok', 'happy')) AS v",
            ),
        );
    }

    public function testJsonType(): void
    {
        self::assertSame(
            ['a' => 1],
            $this->queryValue("SELECT '{\"a\":1}'::JSON AS v"),
        );
        self::assertSame(
            ['a' => 1, 'b' => [1, 2], 'c' => null, 'd' => true],
            $this->queryValue(
                "SELECT '{\"a\":1,\"b\":[1,2],\"c\":null,\"d\":true}'::JSON AS v",
            ),
        );
        self::assertSame(
            [1, 2, 3],
            $this->queryValue("SELECT '[1,2,3]'::JSON AS v"),
        );
        self::assertSame(
            'hello',
            $this->queryValue("SELECT '\"hello\"'::JSON AS v"),
        );
        self::assertSame(
            '123456789012345678901234567890',
            $this->queryValue(
                "SELECT '123456789012345678901234567890'::JSON AS v",
            ),
        );
    }

    public function testNullType(): void
    {
        self::assertNull($this->queryValue('SELECT NULL AS v'));
        self::assertNull($this->queryValue('SELECT NULL::INTEGER AS v'));
    }

    public function testTypeType(): void
    {
        self::assertSame('INTEGER', $this->queryValue('SELECT get_type(42) AS v'));
        self::assertSame(
            'VARCHAR',
            $this->queryValue("SELECT make_type('VARCHAR') AS v"),
        );
        self::assertSame(
            'TIMESTAMP WITH TIME ZONE',
            $this->queryValue(
                "SELECT make_type('TIMESTAMP WITH TIME ZONE') AS v",
            ),
        );
    }

    public function testVariantType(): void
    {
        self::assertSame(
            42,
            $this->queryValue('SELECT CAST(42 AS VARIANT) AS v'),
        );
        self::assertSame(
            'hello',
            $this->queryValue("SELECT CAST('hello' AS VARIANT) AS v"),
        );
        self::assertSame(
            [1, 2, 3],
            $this->queryValue('SELECT CAST([1, 2, 3] AS VARIANT) AS v'),
        );
        self::assertSame(
            ['a' => 1, 'b' => 'x'],
            $this->queryValue("SELECT CAST({'a': 1, 'b': 'x'} AS VARIANT) AS v"),
        );
        self::assertSame(
            ['nested' => [1, ['a' => 'b']]],
            $this->queryValue(
                "SELECT CAST(CAST('{\"nested\":[1,{\"a\":\"b\"}]}' AS JSON) AS VARIANT) AS v",
            ),
        );
        self::assertTrue(
            $this->queryValue('SELECT CAST(TRUE AS VARIANT) AS v'),
        );
        self::assertFalse(
            $this->queryValue('SELECT CAST(FALSE AS VARIANT) AS v'),
        );
        self::assertNull(
            $this->queryValue('SELECT CAST(NULL AS VARIANT) AS v'),
        );

        self::assertSame(
            1.5,
            $this->queryValue('SELECT CAST(1.5::DOUBLE AS VARIANT) AS v'),
        );
        self::assertSame(
            123.45,
            $this->queryValue('SELECT CAST(123.45::DECIMAL(10, 2) AS VARIANT) AS v'),
        );
        self::assertSame(
            '2024-05-06',
            $this->queryValue(
                "SELECT CAST(CAST('2024-05-06' AS DATE) AS VARIANT) AS v",
            ),
        );
        self::assertSame(
            '2024-05-06 12:34:56.123456',
            $this->queryValue(
                "SELECT CAST(CAST('2024-05-06 12:34:56.123456' AS TIMESTAMP) AS VARIANT) AS v",
            ),
        );
        self::assertSame(
            '123e4567-e89b-12d3-a456-426614174000',
            $this->queryValue(
                "SELECT CAST('123e4567-e89b-12d3-a456-426614174000'::UUID AS VARIANT) AS v",
            ),
        );
        self::assertSame(
            '123456789012345678901234567890123456789',
            $this->queryValue(
                'SELECT CAST(123456789012345678901234567890123456789::BIGNUM AS VARIANT) AS v',
            ),
        );
        self::assertSame(
            '10110',
            $this->queryValue("SELECT CAST('10110'::BIT AS VARIANT) AS v"),
        );
    }

    /**
     * Certificate DuckDB generates when the test server starts with SSL
     * enabled; passing it lets the client pin the server without trusting a CA.
     */
    private static function sslCertificate(): string
    {
        $home = getenv('HOME');
        if ($home === false) {
            self::fail('HOME is not set; cannot locate the DuckDB SSL certificate.');
        }
        $certFile = $home . '/.duckdb/extension_data/quack/server.pem';
        $pem = file_get_contents($certFile);
        if ($pem === false) {
            self::fail("Could not read the SSL certificate at {$certFile}.");
        }

        return $pem;
    }

    /**
     * @param array<string,scalar|null> $config
     */
    private function queryValue(string $sql, array $config = []): mixed
    {
        return QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10, $config)->fetchValue($sql);
    }
}
