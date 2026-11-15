<?php

namespace DuckDB\Quack\Tests\Unit\Sql;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Sql\SqlFormatter;
use DuckDB\Quack\Sql\SqlParameter;
use DuckDB\Quack\Sql\SqlParameters;
use DuckDB\Quack\Types\DateValue;
use DuckDB\Quack\Types\DecimalValue;
use DuckDB\Quack\Types\TimestampUnit;
use DuckDB\Quack\Types\TimestampValue;
use DuckDB\Quack\Types\Value;
use PHPUnit\Framework\TestCase;

final class SqlFormatterTest extends TestCase
{
    public function testFormatsPositionalParameters(): void
    {
        $params = SqlParameters::fromArray([
            SqlParameter::value(Value::int(41)),
            SqlParameter::value(Value::string("can't")),
        ]);

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
        $params = SqlParameters::fromArray([SqlParameter::value(Value::int(7))]);

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
        $params = SqlParameters::fromArray([
            'id' => SqlParameter::value(Value::int(7)),
            'label' => SqlParameter::value(Value::string('seven')),
        ]);

        self::assertSame(
            "SELECT 7 AS id, 'seven' AS label",
            SqlFormatter::formatSql('SELECT :id AS id, :label AS label', $params),
        );
    }

    public function testFormatsNestedLists(): void
    {
        $params = SqlParameters::fromArray([
            SqlParameter::list([
                SqlParameter::value(Value::int(1)),
                SqlParameter::list([
                    SqlParameter::value(Value::string('x')),
                ]),
            ]),
        ]);

        self::assertSame(
            "SELECT [1, ['x']] AS value",
            SqlFormatter::formatSql('SELECT ? AS value', $params),
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
            [Value::bytes("\x00\xff"), "from_hex('00ff')"],
            [Value::decimal(new DecimalValue('1234', 10, 2)), '12.34'],
            [Value::date(new DateValue(1)), "DATE '1970-01-02'"],
        ];

        foreach ($cases as [$value, $expected]) {
            self::assertSame($expected, SqlFormatter::valueLiteral($value));
        }
    }

    public function testFormatsTimestampsInUtcAndLocalForms(): void
    {
        self::assertSame(
            "TIMESTAMP '1970-01-01 00:00:01.000'",
            SqlFormatter::valueLiteral(Value::timestamp(
                new TimestampValue(TimestampUnit::Seconds, 1),
            )),
        );
        self::assertSame(
            "TIMESTAMPTZ '1970-01-01 00:00:01.000+00'",
            SqlFormatter::valueLiteral(Value::timestamp(
                new TimestampValue(TimestampUnit::Seconds, 1, true),
            )),
        );
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
            SqlParameters::fromArray([]),
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
            SqlParameters::fromArray(['other' => 1]),
        );
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
