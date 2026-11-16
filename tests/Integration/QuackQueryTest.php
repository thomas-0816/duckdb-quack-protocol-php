<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\Exceptions\Utf8Exception;
use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackQueryTest extends TestCase
{
    public function testQueryAndIteration(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE query_values (i INTEGER, b BIGINT, d DECIMAL(10, 2), v VARCHAR, t TUPLE(integer, string), j JSON)');
        $client->execute(
            'INSERT INTO query_values VALUES (?, ?, ?, ?, ?, ?)',
            [1, 9223372036854775807, 3.141511313212312312, 'hello', "(1, 'a')", [1, 2]],
        );

        $expected = [
            [
                'i' => 1,
                'b' => 9223372036854775807,
                'd' => 3.14,
                'v' => 'hello',
                't' => [1, 'a'],
                'j' => [1, 2],
            ],
        ];

        $rows = $client->fetchAll('SELECT * FROM query_values');
        self::assertSame($expected, $rows);
        self::assertSame(6, count($rows[0]));

        self::assertSame(
            $expected,
            iterator_to_array($client->fetchIterator('SELECT * FROM query_values')),
        );

        self::assertSame(['a' => 42], $client->fetchOne('SELECT 42 as a'));
        self::assertSame([42], $client->fetchOneNumeric('SELECT 42 as a'));
        self::assertSame([0, 1], $client->fetchColumn('SELECT * FROM range(2)'));
        self::assertSame(['a' => 42, 'b' => 21], $client->fetchAllKeyValue("SELECT 'a', 42 UNION ALL SELECT 'b', 21"));
    }

    public function testUpdateAndSelect(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE update_values (n INTEGER NULL, i INTEGER NULL, b BIGINT NULL, d DECIMAL(10, 2) NULL, v VARCHAR NULL)');
        $client->execute(
            'INSERT INTO update_values VALUES (?, ?, ?, ?, ?)',
            [1, 2, 9223372036854775807, 3.141511313212312312, 'hello'],
        );
        $client->execute(
            'UPDATE update_values SET n = ?, i = ?, b = ?, d = ?, v = ?',
            [2, 3, 4, 5.67, 'world'],
        );

        self::assertSame(
            ['n' => 2, 'i' => 3, 'b' => 4, 'd' => 5.67, 'v' => 'world'],
            $client->fetchOne('SELECT * FROM update_values'),
        );
        self::assertSame(
            [2, 3, 4, 5.67, 'world'],
            $client->fetchOneNumeric('SELECT * FROM update_values'),
        );
    }

    public function testNamedParametersWithoutColonPrefix(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE named_values (n INTEGER NULL, i INTEGER NULL, b BIGINT NULL, d DECIMAL(10, 2) NULL, v VARCHAR NULL)');
        $client->execute(
            'INSERT INTO named_values VALUES (:aa, :bb, :cc, :dd, :ee)',
            ['bb' => 2, 'aa' => 1, 'cc' => 9223372036854775807, 'ee' => 'hello', 'dd' => 3.141511313212312312],
        );

        self::assertSame(
            [['n' => 1, 'i' => 2, 'b' => 9223372036854775807, 'd' => 3.14, 'v' => 'hello']],
            $client->fetchAll('SELECT * FROM named_values'),
        );
        self::assertSame(
            [[1, 2, 9223372036854775807, 3.14, 'hello',]],
            $client->fetchAllNumeric('SELECT * FROM named_values'),
        );
    }

    public function testMissingNamedParameters(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE missing_named_values (n INTEGER NULL, i INTEGER NULL, b BIGINT NULL, d DECIMAL(10, 2) NULL, v VARCHAR NULL)');

        try {
            $client->execute(
                'INSERT INTO missing_named_values VALUES (:aa, :bb, :cc, :dd, :ee)',
                ['bb' => 2, 'aa' => 1],
            );
            self::fail('expected missing named parameters to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('missing SQL parameter :cc', $error->getMessage());
        }

        self::assertSame(0, $client->fetchValue('SELECT count(*) FROM missing_named_values'));
    }

    public function testPositionalAndNamedParameterBinding(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE parameter_values (n INTEGER NULL, i UINTEGER NULL, b BIGINT NULL, d DECIMAL(10, 2) NULL, v VARCHAR NULL)');

        $client->execute(
            'INSERT INTO parameter_values VALUES (?, ?, ?, ?, ?)',
            [null, 201, 300, 42.21, 'test'],
        );
        $client->execute(
            'INSERT INTO parameter_values VALUES (:aa, :bb, :cc, :dd, :ee)',
            ['aa' => null, 'bb' => 202, 'cc' => 300, 'dd' => 42.21, 'ee' => 'test'],
        );
        $client->execute(
            'INSERT INTO parameter_values VALUES (:aa, :bb, :cc, :dd, :ee)',
            ['aa' => null, 'bb' => 203, 'cc' => 300, 'dd' => 42.21, 'ee' => 'test'],
        );
        $client->execute(
            'INSERT INTO parameter_values VALUES (?, ?, ?, ?, ?)',
            [null, 204, 300, 42.21, 'test'],
        );
        $client->execute(
            'INSERT INTO parameter_values VALUES (:aa, :bb, :cc, :dd, :ee)',
            ['aa' => null, 'bb' => 205, 'cc' => 300, 'dd' => 42.21, 'ee' => 'test'],
        );

        self::assertSame(
            [
                ['n' => null, 'i' => 201, 'b' => 300, 'd' => 42.21, 'v' => 'test'],
                ['n' => null, 'i' => 202, 'b' => 300, 'd' => 42.21, 'v' => 'test'],
                ['n' => null, 'i' => 203, 'b' => 300, 'd' => 42.21, 'v' => 'test'],
                ['n' => null, 'i' => 204, 'b' => 300, 'd' => 42.21, 'v' => 'test'],
                ['n' => null, 'i' => 205, 'b' => 300, 'd' => 42.21, 'v' => 'test'],
            ],
            $client->fetchAll('SELECT * FROM parameter_values'),
        );
    }

    public function testMissingPositionalAndNamedParameters(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE missing_parameter_values (n INTEGER NULL, i INTEGER NULL, b BIGINT NULL, d DECIMAL(10, 2) NULL, v VARCHAR NULL)');

        try {
            $client->execute(
                'INSERT INTO missing_parameter_values VALUES (:aa, :bb, :cc, :dd, :ee)',
                ['aa' => null, 'bb' => 203],
            );
            self::fail('expected missing named parameters to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('missing SQL parameter :cc', $error->getMessage());
        }

        try {
            $client->execute(
                'INSERT INTO missing_parameter_values VALUES (?, ?, ?, ?, ?)',
                [null, 203],
            );
            self::fail('expected missing positional parameters to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('SQL has more positional placeholders than parameters', $error->getMessage());
        }
    }

    public function testInsertReturning(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE returning_values (i INTEGER)');

        self::assertSame(
            [['i' => 42]],
            $client->fetchAll('INSERT INTO returning_values SELECT 42 RETURNING *'),
        );
    }

    public function testFetchIteratorStreamsRows(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $count = 0;
        foreach ($client->fetchIterator('SELECT range::INTEGER AS n FROM range(10000) ORDER BY n') as $row) {
            $count++;
        }
        foreach ($client->fetchIterator('SELECT range::INTEGER AS n FROM range(10000) ORDER BY n') as $row) {
            $count++;
        }
        self::assertSame(20000, $count);
        self::assertSame(42, $client->fetchValue('SELECT 42'));

        $iterator = $client->fetchIterator('SELECT i FROM range(2500) t(i)');
        self::assertSame(['i' => 0], $iterator->current());
        unset($iterator);

        self::assertSame(42, $client->fetchValue('SELECT 42'));
    }

    public function testNulBytesInParametersAndResults(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['a' => 1, 'b' => "te\0st"]],
            $client->fetchAll('SELECT ? AS a, ? AS b', [1, "te\0st"]),
        );

        $client->execute('CREATE TEMP TABLE nul_values (v VARCHAR)');
        $client->execute('INSERT INTO nul_values VALUES (?)', ["he\0llo"]);
        $client->execute('INSERT INTO nul_values VALUES (:aa)', ['aa' => "he\0llo"]);
        $client->execute('INSERT INTO nul_values VALUES (?)', ["he\0llo"]);
        $client->execute('INSERT INTO nul_values VALUES (:aa)', ['aa' => "he\0llo"]);

        self::assertSame(
            array_fill(0, 4, ['v' => "he\0llo"]),
            $client->fetchAll('SELECT * FROM nul_values'),
        );
    }

    public function testArrayParameter(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE array_values (v VARCHAR[])');
        $client->execute('INSERT INTO array_values VALUES (?)', [['hello', 'world']]);

        self::assertSame(
            [['v' => ['hello', 'world']]],
            $client->fetchAll('SELECT * FROM array_values'),
        );
    }

    public function testJsonValuesFromObjectsAndArrays(): void
    {
        $object = new \stdClass();
        $object->foo = 'bar';
        $object->hello = 'world';

        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE json_values (j JSON)');
        $client->execute('INSERT INTO json_values VALUES (?)', [$object]);
        $client->execute('INSERT INTO json_values VALUES (:j)', ['j' => ['foo', 'bar']]);
        $client->execute('INSERT INTO json_values VALUES (:j)', ['j' => $object]);
        $client->execute('INSERT INTO json_values VALUES (?)', [json_encode($object)]);
        $client->execute('INSERT INTO json_values VALUES (?)', [json_encode(['foo', 'bar'])]);

        self::assertSame(
            [
                ['j' => ['foo' => 'bar', 'hello' => 'world']],
                ['j' => ['foo', 'bar']],
                ['j' => ['foo' => 'bar', 'hello' => 'world']],
                ['j' => ['foo' => 'bar', 'hello' => 'world']],
                ['j' => ['foo', 'bar']],
            ],
            $client->fetchAll('SELECT * FROM json_values'),
        );
    }

    public function testBigIntParameterAndResult(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE bigint_values (b BIGINT NULL)');
        $client->execute('INSERT INTO bigint_values VALUES (:aa), (:bb)', ['aa' => PHP_INT_MAX, 'bb' => PHP_INT_MIN]);

        self::assertSame(
            [['b' => PHP_INT_MAX], ['b' => PHP_INT_MIN]],
            $client->fetchAll('SELECT * FROM bigint_values'),
        );
    }

    public function testSequencesWithReturning(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE OR REPLACE SEQUENCE query_sequence');

        try {
            $client->execute("CREATE TEMP TABLE sequence_values (i INTEGER PRIMARY KEY DEFAULT nextval('query_sequence'), v VARCHAR)");

            self::assertSame(
                [['i' => 1, 'v' => 'a']],
                $client->fetchAll("INSERT INTO sequence_values (v) VALUES ('a') RETURNING *"),
            );
            self::assertSame(
                [['i' => 2, 'v' => 'b']],
                $client->fetchAll("INSERT INTO sequence_values (v) VALUES ('b') RETURNING *"),
            );
            self::assertSame(
                [
                    ['i' => 1, 'v' => 'a'],
                    ['i' => 2, 'v' => 'b'],
                ],
                $client->fetchAll('SELECT * FROM sequence_values'),
            );
        } finally {
            $client->execute('DROP SEQUENCE IF EXISTS query_sequence');
        }
    }

    public function testCreateTableAndSequenceCommit(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->beginTransaction();
        try {
            $client->execute("CREATE TABLE t1 (i INTEGER)");
            $client->execute("CREATE SEQUENCE s1");
            $client->execute("INSERT INTO t1 VALUES (nextval('s1'))");

            self::assertSame([['i' => 1]], $client->fetchAll("SELECT * FROM t1"));
            self::assertSame(2, $client->fetchValue("SELECT nextval('s1')"));
        } finally {
            $client->rollback();
        }
    }

    public function testCreateTableAndSequenceRollback(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->beginTransaction();
        $client->execute("CREATE TABLE t1 (i INTEGER)");
        $client->execute("CREATE SEQUENCE s1");
        $client->execute("INSERT INTO t1 VALUES (nextval('s1'))");
        $client->rollback();

        try {
            $client->fetchAll("SELECT * FROM t1");
            self::fail('expected the rolled back table to be gone');
        } catch (ServerException $error) {
            self::assertStringContainsString("Table with name t1 does not exist", $error->getMessage());
        }

        try {
            $client->fetchValue("SELECT nextval('s1')");
            self::fail('expected the rolled back sequence to be gone');
        } catch (ServerException $error) {
            self::assertStringContainsString("Sequence with name s1 does not exist", $error->getMessage());
        }
    }

    public function testUpdateChangedRowCount(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE update_count (v VARCHAR)');
        $client->execute('INSERT INTO update_count VALUES (?)', ['foo']);
        $client->execute('INSERT INTO update_count VALUES (?)', ['bar']);

        self::assertSame(
            1,
            $client->execute("UPDATE update_count SET v = 'asd' WHERE v = 'foo'"),
        );
    }

    public function testNulStringParametersAndMissingValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE nul_parameters (v1 varchar, v2 varchar)');
        $client->execute(
            'INSERT INTO nul_parameters VALUES (?, ?)',
            ['foo1' . chr(0) . '2', 'foo2' . chr(0) . '2'],
        );

        try {
            $client->execute('INSERT INTO nul_parameters VALUES (?, ?)');
            self::fail('expected missing positional parameters to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('SQL has more positional placeholders than parameters', $error->getMessage());
        }

        try {
            $client->execute('INSERT INTO nul_parameters VALUES (?, ?)', ['a']);
            self::fail('expected a missing positional parameter to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('SQL has more positional placeholders than parameters', $error->getMessage());
        }

        $client->execute('INSERT INTO nul_parameters VALUES (?, ?)', ['b', 'c']);
        $client->execute(
            'INSERT INTO nul_parameters VALUES (?, ?)',
            ['foo1' . chr(0) . '3', 'foo1' . chr(0) . '4'],
        );

        self::assertSame(
            [
                ['v1' => 'foo1' . chr(0) . '2', 'v2' => 'foo2' . chr(0) . '2'],
                ['v1' => 'b', 'v2' => 'c'],
                ['v1' => 'foo1' . chr(0) . '3', 'v2' => 'foo1' . chr(0) . '4'],
            ],
            $client->fetchAll('SELECT * FROM nul_parameters'),
        );
    }

    public function testStringToIntegerConversionErrors(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE integer_values (v1 int, v2 int, v3 int)');

        try {
            $client->execute(
                'INSERT INTO integer_values VALUES (?, ?, ?)',
                ['foo1', 'foo2', 'foo3'],
            );
            self::fail('expected the string to integer conversion to fail');
        } catch (ServerException $error) {
            self::assertStringContainsString("Could not convert string 'foo1' to INT32", $error->getMessage());
        }

        try {
            $client->execute('INSERT INTO integer_values VALUES (?, ?, ?)', ['foo1']);
            self::fail('expected a missing positional parameter to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('SQL has more positional placeholders than parameters', $error->getMessage());
        }
    }

    public function testJsonFromTypedObject(): void
    {
        $object = new QueryJsonFixture();
        $object->i = 42;
        $object->s = 'Foo Bar';

        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE typed_json_values (j JSON)');
        $client->execute('INSERT INTO typed_json_values VALUES (?)', [$object]);
        $client->execute('INSERT INTO typed_json_values VALUES (?)', [json_encode($object)]);

        $expected = [['j' => ['i' => 42, 's' => 'Foo Bar']]];
        self::assertSame(
            [$expected[0], $expected[0]],
            $client->fetchAll('SELECT * FROM typed_json_values'),
        );
    }

    public function testBlobResources(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE blob_values (b BLOB)');

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            self::fail('could not open a memory stream');
        }
        fwrite($stream, "\xAA\xAB\xAC");
        rewind($stream);
        $client->execute('INSERT INTO blob_values VALUES (?)', [$stream]);
        fclose($stream);

        self::assertSame(
            [['b' => "\xAA\xAB\xAC"]],
            $client->fetchAll('SELECT * FROM blob_values'),
        );

        $client->execute('CREATE TEMP TABLE empty_blob_values (b BLOB)');
        $stream = fopen('php://memory', 'r');
        if ($stream === false) {
            self::fail('could not open a memory stream');
        }
        $client->execute('INSERT INTO empty_blob_values VALUES (:b)', ['b' => $stream]);
        fclose($stream);

        self::assertSame(
            [['b' => '']],
            $client->fetchAll('SELECT * FROM empty_blob_values'),
        );
    }

    public function testRecursiveValuesAreRejected(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $object = new \stdClass();
        $object->myself = $object;

        try {
            $client->fetchAll('SELECT ?', [$object]);
            self::fail('expected the recursive object to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('could not JSON-encode SQL parameter of type stdClass', $error->getMessage());
        }

        try {
            $client->fetchAll('SELECT :foo', ['foo' => $object]);
            self::fail('expected the recursive named object to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('could not JSON-encode SQL parameter of type stdClass', $error->getMessage());
        }

        $recursive = [1];
        $recursive[] = &$recursive;

        try {
            $client->fetchAll('SELECT ?', [$recursive]);
            self::fail('expected the recursive array to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('could not JSON-encode SQL array parameter', $error->getMessage());
        }
    }

    public function testJsonParameterCasts(): void
    {
        $object = new \stdClass();
        $object->key = 'value';

        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [[
                'a' => ['a', 1],
                'b' => ['key' => 'value'],
                'c' => ['key' => 'value'],
            ]],
            $client->fetchAll(
                'SELECT ?::json AS a, ?::json AS b, ?::json AS c',
                [['a', 1], ['key' => 'value'], $object],
            ),
        );

        self::assertSame(
            [[
                'data' => ['key' => 'value'],
                'object' => ['key' => 'value'],
            ]],
            $client->fetchAll(
                'SELECT :data::json AS data, :object::json AS object',
                ['data' => ['key' => 'value'], 'object' => $object],
            ),
        );
    }

    public function testNamedParameterResolution(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(42, $client->fetchValue('SELECT :value', ['value' => 42]));

        try {
            $client->fetchValue('SELECT :value', ['invalid' => 'x']);
            self::fail('expected the unresolved named parameter to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('missing SQL parameter :value', $error->getMessage());
        }
    }

    public function testLargeResultStreamingSums(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $sum = 0;
        foreach ($client->fetchIteratorNumeric('SELECT * FROM generate_series(1_000_000)') as $row) {
            $value = $row[0];
            if (!is_int($value)) {
                self::fail('expected an integer value');
            }
            $sum += $value;
        }
        self::assertSame(500000500000, $sum);

        $sum = 0;
        foreach ($client->fetchIterator('SELECT i FROM generate_series(1_000_000) t(i)') as $row) {
            $value = $row['i'];
            if (!is_int($value)) {
                self::fail('expected an integer value');
            }
            $sum += $value;
        }
        self::assertSame(500000500000, $sum);

        try {
            $client->fetchValue('SELECT ?::integer');
            self::fail('expected a missing positional parameter to be rejected');
        } catch (ProtocolException $error) {
            self::assertStringContainsString('SQL has more positional placeholders than parameters', $error->getMessage());
        }

        self::assertSame(42, $client->fetchValue('SELECT ?::integer', [42]));
        self::assertSame(43, $client->fetchValue('SELECT ?::integer', [43]));
    }

    public function testBooleanValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE boolean_values (bool1 BOOLEAN, bool2 BOOLEAN)');
        $client->execute('INSERT INTO boolean_values VALUES (?, ?)', [true, false]);
        $client->execute('INSERT INTO boolean_values VALUES (?, ?)', [4, '0']);
        $client->execute('INSERT INTO boolean_values VALUES (?, ?)', ['1', false]);

        self::assertSame(
            [
                ['bool1' => true, 'bool2' => false],
                ['bool1' => true, 'bool2' => false],
                ['bool1' => true, 'bool2' => false],
            ],
            $client->fetchAll('SELECT * FROM boolean_values'),
        );
    }

    public function testInvalidUtf8Parameters(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        try {
            $client->fetchValue('SELECT ?::VARCHAR', ["\xFF"]);
            self::fail('expected the invalid UTF-8 positional parameter to be rejected');
        } catch (Utf8Exception $error) {
            self::assertStringContainsString('invalid UTF-8', $error->getMessage());
        }

        try {
            $client->fetchValue('SELECT :value::VARCHAR', ['value' => "\xFF"]);
            self::fail('expected the invalid UTF-8 named parameter to be rejected');
        } catch (Utf8Exception $error) {
            self::assertStringContainsString('invalid UTF-8', $error->getMessage());
        }
    }

    public function testConversionErrors(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        try {
            $client->fetchAll('SELECT i::TINYINT FROM range(1000) as t(i)');
            self::fail('expected the tinyint overflow to be rejected');
        } catch (ServerException $error) {
            self::assertStringContainsString(
                "Type INT64 with value 128 can't be cast because the value is out of range for the destination type INT8",
                $error->getMessage(),
            );
        }

        try {
            $client->fetchAll(
                'SELECT ?::integer, ?::integer, ?::integer',
                [42, 'invalid', '42foo'],
            );
            self::fail('expected the invalid integer parameter to be rejected');
        } catch (ServerException $error) {
            self::assertStringContainsString("Could not convert string 'invalid' to INT32", $error->getMessage());
        }
    }

}

final class QueryJsonFixture
{
    public int $i;

    public string $s;
}
