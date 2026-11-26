<?php

namespace DuckDB\Quack\Tests\Unit\Sql;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Sql\SqlFormatter;
use DuckDB\Quack\Sql\SqlParameter;
use DuckDB\Quack\Types\DecimalValue;
use DuckDB\Quack\Types\Value;
use PHPUnit\Framework\TestCase;

final class SqlFormatterTest extends TestCase
{
    public function testFormatsPositionalParameters(): void
    {
        $params = [
            SqlParameter::value(Value::int(41)),
            SqlParameter::value(Value::string("can't")),
        ];

        self::assertSame(
            "SELECT 41 + 1 AS answer, 'can''t' AS label",
            SqlFormatter::formatSql('SELECT ? + 1 AS answer, ? AS label', $params),
        );
    }

    public function testPlaceholdersInsideStringsIdentifiersAndCommentsAreUntouched(): void
    {
        $sql = <<<'SQL'
            SELECT '?' AS "?", ? AS real_value -- ? in a line comment
            /* ? in a block comment */
            SQL;
        $params = [SqlParameter::value(Value::int(7))];

        self::assertSame(
            <<<'SQL'
                SELECT '?' AS "?", 7 AS real_value -- ? in a line comment
                /* ? in a block comment */
                SQL,
            SqlFormatter::formatSql($sql, $params),
        );
    }

    public function testFormatsNamedParameters(): void
    {
        $params = [
            'id' => SqlParameter::value(Value::int(7)),
            'label' => SqlParameter::value(Value::string('seven')),
        ];

        self::assertSame(
            "SELECT 7 AS id, 'seven' AS label",
            SqlFormatter::formatSql('SELECT :id AS id, :label AS label', $params),
        );
    }

    public function testBindsPrecededByAlphanumericCharactersAreNotPlaceholders(): void
    {
        self::assertSame(
            'SELECT [10, 20, 30][i:j] AS slice, 7 AS id FROM (VALUES (2, 3)) t(i, j)',
            SqlFormatter::formatSql(
                'SELECT [10, 20, 30][i:j] AS slice, :id AS id FROM (VALUES (2, 3)) t(i, j)',
                ['id' => 7],
            ),
        );
    }

    public function testStructLiteralKeysRequireASpaceAfterTheColon(): void
    {
        self::assertSame(
            "SELECT {'a': 1} AS a, {'b': true} AS b, 7 AS id",
            SqlFormatter::formatSql(
                "SELECT {'a': 1} AS a, {'b': true} AS b, :id AS id",
                ['id' => 7],
            ),
        );

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('missing SQL parameter :1');
        SqlFormatter::formatSql(
            "SELECT {'a':1} AS a, {'b':true} AS b, :id AS id",
            ['id' => 7],
        );
    }

    public function testAllowsDigitLeadingNamedParameters(): void
    {
        self::assertSame(
            'SELECT 1 AS a, 2 AS b',
            SqlFormatter::formatSql(
                'SELECT :1a AS a, :01 AS b',
                ['1a' => 1, '01' => 2],
            ),
        );
    }

    public function testKeepsDoubleColonCastsWithNamedParameters(): void
    {
        self::assertSame(
            'SELECT 7::INT AS id',
            SqlFormatter::formatSql('SELECT :id::INT AS id', ['id' => 7]),
        );
    }

    public function testFormatsScalarLiterals(): void
    {
        $cases = [
            [Value::null(), 'NULL'],
            [Value::bool(true), 'TRUE'],
            [Value::bool(false), 'FALSE'],
            [Value::int(-3), '-3'],
            [Value::hugeInt('170141183460469231731687303715884105727'), '170141183460469231731687303715884105727'],
            [Value::double(1.5), '1.5'],
            [Value::double(NAN), "'NaN'::DOUBLE"],
            [Value::double(INF), "'Infinity'::DOUBLE"],
            [Value::double(-INF), "'-Infinity'::DOUBLE"],
            [Value::float(NAN), "'NaN'::FLOAT"],
            [Value::float(INF), "'Infinity'::FLOAT"],
            [Value::float(-INF), "'-Infinity'::FLOAT"],
            [Value::bytes("\x00\xff"), "from_hex('00ff')"],
            [Value::decimal(new DecimalValue('1234', 10, 2)), '12.34'],
        ];

        foreach ($cases as [$value, $expected]) {
            self::assertSame($expected, SqlFormatter::valueLiteral($value));
        }
    }

    public function testDetectsPhpTypesAutomatically(): void
    {
        self::assertSame(
            "SELECT 41, 'can''t', TRUE, 1.5, NULL",
            SqlFormatter::formatSql(
                'SELECT ?, ?, ?, ?, ?',
                [41, "can't", true, 1.5, null],
            ),
        );
    }

    public function testJsonEncodesSequentialArrays(): void
    {
        self::assertSame(
            "SELECT '[1,2,3]' AS value",
            SqlFormatter::formatSql('SELECT ? AS value', [[1, 2, 3]]),
        );
    }

    public function testJsonEncodesStringKeyedArrays(): void
    {
        self::assertSame(
            'SELECT \'{"name":"Ada","tags":["math","code"]}\' AS value',
            SqlFormatter::formatSql(
                'SELECT ? AS value',
                [['name' => 'Ada', 'tags' => ['math', 'code']]],
            ),
        );
    }

    public function testDetectsNamedRawArrays(): void
    {
        self::assertSame(
            "SELECT 7 AS id, 'seven' AS label",
            SqlFormatter::formatSql(
                'SELECT :id AS id, :label AS label',
                ['id' => 7, 'label' => 'seven'],
            ),
        );
    }

    public function testDetectsEnumsStringablesAndByteResources(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\x00\xff");
        rewind($stream);

        self::assertSame(
            "SELECT 2, 'Object', from_hex('00ff')",
            SqlFormatter::formatSql(
                'SELECT ?, ?, ?',
                [ExampleBackedEnum::Two, ExampleUnitEnum::Object, $stream],
            ),
        );
    }

    public function testJsonEncodesArbitraryObjects(): void
    {
        self::assertSame(
            'SELECT \'{"answer":42}\' AS value',
            SqlFormatter::formatSql('SELECT ? AS value', [new ExampleJsonObject()]),
        );
    }

    public function testRejectsPlaceholderCountMismatch(): void
    {
        $this->expectException(ProtocolException::class);

        SqlFormatter::formatSql(
            'SELECT ?',
            [],
        );
    }

    public function testEmptyParametersReturnSqlWithoutScanning(): void
    {
        $sql = <<<'SQL'
            INSERT INTO t SELECT value->>'id' FROM json_each('[{"id":1},{"id":2}]') -- no placeholders
            SQL;

        self::assertSame($sql, SqlFormatter::formatSql($sql, []));
    }

    public function testEmptyParametersStillIgnorePlaceholdersInsideStrings(): void
    {
        self::assertSame(
            "SELECT '?', 'it''s ?' AS \"?\" /* ? */",
            SqlFormatter::formatSql("SELECT '?', 'it''s ?' AS \"?\" /* ? */", []),
        );
    }

    public function testIgnoresPlaceholdersInsideEscapeStrings(): void
    {
        $sql = <<<'SQL'
            SELECT e'it''s ?' AS a, E'line1\nline2 ?' AS b, e'it''s :name' AS c, ? AS real_value
            SQL;

        self::assertSame(
            <<<'SQL'
                SELECT e'it''s ?' AS a, E'line1\nline2 ?' AS b, e'it''s :name' AS c, 7 AS real_value
                SQL,
            SqlFormatter::formatSql($sql, [7]),
        );
    }

    public function testBackslashDoesNotEscapeQuotesInEscapeStrings(): void
    {
        $sql = <<<'SQL'
            SELECT e'it\'s ?'
            SQL;

        self::assertSame(
            <<<'SQL'
                SELECT e'it\'s 7'
                SQL,
            SqlFormatter::formatSql($sql, [7]),
        );
    }

    public function testIgnoresNamedPlaceholdersInsideDollarQuotedStrings(): void
    {
        $sql = <<<'SQL'
            SELECT $$ ? :secret $$ AS a, $tag$ ? :secret $tag$ AS b, $outer$ $$ :x $$ $outer$ AS c, :id AS id
            SQL;

        self::assertSame(
            <<<'SQL'
                SELECT $$ ? :secret $$ AS a, $tag$ ? :secret $tag$ AS b, $outer$ $$ :x $$ $outer$ AS c, 7 AS id
                SQL,
            SqlFormatter::formatSql($sql, ['id' => 7]),
        );
    }

    public function testUnterminatedDollarQuoteOnlyCopiesTheDelimiter(): void
    {
        // DuckDB rejects this statement, so the `?` is still a placeholder.
        self::assertSame('SELECT $tag$ 7', SqlFormatter::formatSql('SELECT $tag$ ?', [7]));
    }

    public function testTreatsDollarVariablesAsText(): void
    {
        $sql = "SELECT '$', \$1, \$123abc, \$name, ?";

        self::assertSame("SELECT '$', \$1, \$123abc, \$name, 7", SqlFormatter::formatSql($sql, [7]));
    }

    public function testEmptyParametersIgnoreDoubleColonCasts(): void
    {
        self::assertSame(
            "SELECT '?', 1::INT, 2.5::DECIMAL(10,2)",
            SqlFormatter::formatSql("SELECT '?', 1::INT, 2.5::DECIMAL(10,2)", []),
        );
    }

    public function testRejectsMissingNamedParameter(): void
    {
        $this->expectException(ProtocolException::class);

        SqlFormatter::formatSql(
            'SELECT :missing',
            ['other' => 1],
        );
    }

    public function testLeavesDuckDbVariablesUntouched(): void
    {
        $sql = "SET VARIABLE foo = 'bar'; SELECT \$foo;";

        self::assertSame($sql, SqlFormatter::formatSql($sql, ['foo' => 'ignored']));
    }

    public function testDollarVariablesAreNotReplacedByNamedParameters(): void
    {
        self::assertSame(
            'SELECT $foo AS variable, 7 AS id',
            SqlFormatter::formatSql(
                'SELECT $foo AS variable, :id AS id',
                ['foo' => 'ignored', 'id' => 7],
            ),
        );
    }

    public function testDollarVariablesCanAppearAlongsidePositionalPlaceholders(): void
    {
        self::assertSame(
            'SELECT $foo, 7',
            SqlFormatter::formatSql('SELECT $foo, ?', [7]),
        );
    }

    public function testRejectsMixedPositionalAndNamedPlaceholders(): void
    {
        $cases = [
            ['SELECT ?, :id', [1]],
            ['SELECT :id, ?', ['id' => 1]],
        ];

        foreach ($cases as [$sql, $params]) {
            try {
                SqlFormatter::formatSql($sql, $params);
                self::fail("expected mixed-style rejection for {$sql}");
            } catch (ProtocolException) {
                self::addToAssertionCount(1);
            }
        }
    }
}

enum ExampleBackedEnum: int
{
    case Two = 2;
}

enum ExampleUnitEnum
{
    case Object;
}

final class ExampleJsonObject
{
    public int $answer = 42;
}
