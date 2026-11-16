<?php

namespace DuckDB\Quack\Tests\Unit\Types;

use DuckDB\Quack\Types\ChildType;
use DuckDB\Quack\Types\DateValue;
use DuckDB\Quack\Types\DecimalValue;
use DuckDB\Quack\Types\Info\StringTypeInfo;
use DuckDB\Quack\Types\Info\StructTypeInfo;
use DuckDB\Quack\Types\IntervalValue;
use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\LogicalTypeId;
use DuckDB\Quack\Types\LogicalTypes;
use DuckDB\Quack\Types\TemporalFormatter;
use DuckDB\Quack\Types\TimestampUnit;
use DuckDB\Quack\Types\TimestampValue;
use DuckDB\Quack\Types\TimeTzValue;
use DuckDB\Quack\Types\TimeUnit;
use DuckDB\Quack\Types\TimeValue;
use DuckDB\Quack\Types\Value;
use PHPUnit\Framework\TestCase;

final class ValueToPhpTest extends TestCase
{
    public function testScalarValuesConvertToNativePhp(): void
    {
        self::assertNull(Value::null()->toPhp());
        self::assertTrue(Value::bool(true)->toPhp());
        self::assertSame(42, Value::int(42)->toPhp());
        self::assertSame(1.5, Value::double(1.5)->toPhp());
        self::assertSame('hello', Value::string('hello')->toPhp());
        self::assertSame("\x00\x01", Value::bytes("\x00\x01")->toPhp());
        self::assertSame(
            '170141183460469231731687303715884105727',
            Value::hugeInt('170141183460469231731687303715884105727')->toPhp(),
        );
        self::assertSame(
            '18446744073709551615',
            Value::uHugeInt('18446744073709551615')->toPhp(),
        );
    }

    public function testDatesConvertToIsoStrings(): void
    {
        self::assertSame('1970-01-01', Value::date(new DateValue(0))->toPhp());
        self::assertSame('1970-01-02', Value::date(new DateValue(1))->toPhp());
        self::assertSame('1969-12-31', Value::date(new DateValue(-1))->toPhp());
        self::assertSame('2000-01-01', Value::date(new DateValue(10_957))->toPhp());
        self::assertSame('0001-01-01', Value::date(new DateValue(-719_162))->toPhp());
        self::assertSame('9999-12-31', Value::date(new DateValue(2_932_896))->toPhp());
        self::assertSame('infinity', Value::date(new DateValue(2_147_483_647))->toPhp());
        self::assertSame('-infinity', Value::date(new DateValue(-2_147_483_648))->toPhp());
    }

    public function testTimesConvertToStrings(): void
    {
        self::assertSame(
            '00:00:01.000001',
            Value::time(new TimeValue(TimeUnit::Micros, 1_000_001))->toPhp(),
        );
        self::assertSame(
            '00:00:01.000000001',
            Value::time(new TimeValue(TimeUnit::Nanos, 1_000_000_001))->toPhp(),
        );
        self::assertSame(
            '23:59:59',
            Value::time(new TimeValue(TimeUnit::Micros, 86_399_000_000))->toPhp(),
        );
    }

    public function testTimeWithTimeZoneIncludesOffset(): void
    {
        $maxOffset = 16 * 60 * 60 - 1;
        $micros = (12 * 3_600 + 34 * 60 + 56) * 1_000_000;
        $bits = ($micros << 24) | ($maxOffset - 3_600);

        self::assertSame(
            '12:34:56+01:00',
            Value::timeTz(new TimeTzValue($bits))->toPhp(),
        );
    }

    public function testTimestampsConvertToStrings(): void
    {
        self::assertSame(
            '1970-01-01 00:00:01',
            Value::timestamp(new TimestampValue(TimestampUnit::Seconds, 1))->toPhp(),
        );
        self::assertSame(
            '1970-01-01 00:00:01.000001',
            Value::timestamp(new TimestampValue(TimestampUnit::Micros, 1_000_001))->toPhp(),
        );
        self::assertSame(
            '1969-12-31 23:59:59.999999',
            Value::timestamp(new TimestampValue(TimestampUnit::Micros, -1))->toPhp(),
        );
        $defaultTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            self::assertSame(
                '1970-01-01 00:00:01+00',
                Value::timestamp(new TimestampValue(TimestampUnit::Seconds, 1, true))->toPhp(),
            );
        } finally {
            date_default_timezone_set($defaultTimezone);
        }
        self::assertSame(
            'infinity',
            Value::timestamp(new TimestampValue(TimestampUnit::Micros, PHP_INT_MAX))->toPhp(),
        );
    }

    public function testTimestampsWithTimezoneRenderInPhpDefaultTimezone(): void
    {
        self::assertSame(
            '1970-01-01 00:00:01+00',
            Value::timestamp(new TimestampValue(TimestampUnit::Seconds, 1, true))->toPhp(),
        );
        self::assertSame(
            '1970-01-01 00:00:01.000001+00',
            Value::timestamp(new TimestampValue(TimestampUnit::Micros, 1_000_001, true))->toPhp(),
        );

        self::assertSame(
            '1970-01-01 00:00:01+00',
            Value::timestamp(new TimestampValue(TimestampUnit::Seconds, 1, true))->toPhp(),
        );

        self::assertSame(
            '1970-01-01 00:00:27+00',
            Value::timestamp(new TimestampValue(TimestampUnit::Seconds, 27, true))->toPhp(),
        );
    }

    public function testIntervalsConvertToStrings(): void
    {
        self::assertSame(
            '1 year 2 months 3 days 00:00:00.000001',
            Value::interval(new IntervalValue(14, 3, 1))->toPhp(),
        );
        self::assertSame(
            '00:00:00',
            Value::interval(new IntervalValue(0, 0, 0))->toPhp(),
        );
        self::assertSame(
            '-1 day -01:02:03.5',
            Value::interval(new IntervalValue(0, -1, -3_723_500_000))->toPhp(),
        );
    }

    public function testDecimalsConvertToNumbersWhenSafe(): void
    {
        self::assertSame(
            12.34,
            Value::decimal(new DecimalValue(1_234, 10, 2))->toPhp(),
        );
        self::assertSame(
            '12345678901234567890.1234567890',
            Value::decimal(new DecimalValue('123456789012345678901234567890', 38, 10))->toPhp(),
        );
    }

    public function testListsAndStructsConvertRecursively(): void
    {
        self::assertSame(
            [1, 'two', null, [3, 4]],
            Value::list([
                Value::int(1),
                Value::string('two'),
                Value::null(),
                Value::list([Value::int(3), Value::int(4)]),
            ])->toPhp(),
        );

        self::assertSame(
            ['name' => 'Ada', 'tags' => ['math', 'code'], 'age' => 36],
            Value::struct([
                'name' => Value::string('Ada'),
                'tags' => Value::list([Value::string('math'), Value::string('code')]),
                'age' => Value::int(36),
            ])->toPhp(),
        );
    }

    public function testMapsConvertToAssociativeArraysWhenTyped(): void
    {
        $mapType = LogicalTypes::map(LogicalTypes::varchar(), LogicalTypes::integer());
        $mapValue = Value::list([
            Value::struct([
                'key' => Value::string('a'),
                'value' => Value::int(1),
            ]),
            Value::struct([
                'key' => Value::string('b'),
                'value' => Value::int(2),
            ]),
        ]);

        self::assertSame(['a' => 1, 'b' => 2], $mapValue->toPhp($mapType));
        self::assertSame(
            [['key' => 'a', 'value' => 1], ['key' => 'b', 'value' => 2]],
            $mapValue->toPhp(),
        );

        $listOfMaps = Value::list([$mapValue]);
        self::assertSame(
            [['a' => 1, 'b' => 2]],
            $listOfMaps->toPhp(LogicalTypes::list($mapType)),
        );

        $structWithMap = Value::struct(['m' => $mapValue]);
        self::assertSame(
            ['m' => ['a' => 1, 'b' => 2]],
            $structWithMap->toPhp(LogicalTypes::struct([
                new ChildType('m', $mapType),
            ])),
        );
    }

    public function testJsonValuesDecodeWhenTyped(): void
    {
        $jsonType = new LogicalType(
            LogicalTypeId::Varchar,
            new StringTypeInfo('JSON', ''),
        );

        self::assertSame(
            ['a' => 1, 'b' => [1, 2], 'c' => null],
            Value::string('{"a":1,"b":[1,2],"c":null}')->toPhp($jsonType),
        );
        self::assertSame(
            [1, 2, 3],
            Value::string('[1,2,3]')->toPhp($jsonType),
        );
        self::assertSame(
            '123456789012345678901234567890',
            Value::string('123456789012345678901234567890')->toPhp($jsonType),
        );
        self::assertSame(
            [['a' => 1]],
            Value::list([Value::string('{"a":1}')])->toPhp(
                LogicalTypes::list($jsonType),
            ),
        );

        // DuckDB writes non-finite doubles as bare tokens; PDO returns them
        // unchanged instead of rejecting the value.
        self::assertSame('NaN', Value::string('NaN')->toPhp($jsonType));
        self::assertSame('Infinity', Value::string('Infinity')->toPhp($jsonType));
        self::assertSame('-Infinity', Value::string('-Infinity')->toPhp($jsonType));

        // Without type information the raw JSON text is preserved.
        self::assertSame(
            '{"a":1}',
            Value::string('{"a":1}')->toPhp(),
        );
    }

    public function testUnionValuesDecodeToTheirActiveMember(): void
    {
        $unionType = LogicalTypes::union(new StructTypeInfo(null, [
            new ChildType('', LogicalTypes::utinyint()),
            new ChildType('answer', LogicalTypes::integer()),
            new ChildType('label', LogicalTypes::varchar()),
        ]));

        self::assertSame(
            ['answer' => 42],
            Value::struct([
                '' => Value::uint(0),
                'answer' => Value::int(42),
                'label' => Value::null(),
            ])->toPhp($unionType),
        );
        self::assertSame(
            ['label' => 'hi'],
            Value::struct([
                '' => Value::uint(1),
                'answer' => Value::null(),
                'label' => Value::string('hi'),
            ])->toPhp($unionType),
        );
        self::assertSame(
            ['answer' => null],
            Value::struct([
                '' => Value::uint(0),
                'answer' => Value::null(),
                'label' => Value::null(),
            ])->toPhp($unionType),
        );
        self::assertSame(
            [['answer' => 7]],
            Value::list([
                Value::struct([
                    '' => Value::uint(0),
                    'answer' => Value::int(7),
                    'label' => Value::null(),
                ]),
            ])->toPhp(LogicalTypes::list($unionType)),
        );
    }

    public function testVariantValuesDecodeToNativePhp(): void
    {
        $variantType = LogicalTypes::variant();

        self::assertSame(
            42,
            self::variantValue([], [], [[5, 0]], "\x2a\x00\x00\x00")
                ->toPhp($variantType),
        );
        self::assertSame(
            'hello',
            self::variantValue([], [], [[16, 0]], "\x05hello")
                ->toPhp($variantType),
        );
        self::assertSame(
            [1, 2, 3],
            self::variantValue(
                [],
                [[null, 1], [null, 2], [null, 3]],
                [[30, 0], [5, 2], [5, 6], [5, 10]],
                "\x03\x00" . pack('V', 1) . pack('V', 2) . pack('V', 3),
            )->toPhp($variantType),
        );
        self::assertSame(
            ['a' => 1, 'b' => 'x'],
            self::variantValue(
                ['a', 'b'],
                [[0, 1], [1, 2]],
                [[29, 0], [5, 2], [16, 6]],
                "\x02\x00" . pack('V', 1) . "\x01x",
            )->toPhp($variantType),
        );
        self::assertTrue(
            self::variantValue([], [], [[1, 0]], '')->toPhp($variantType),
        );
        self::assertFalse(
            self::variantValue([], [], [[2, 0]], '')->toPhp($variantType),
        );
        self::assertNull(
            self::variantValue([], [], [[0, 0]], '')->toPhp($variantType),
        );
        self::assertSame(
            1.5,
            self::variantValue([], [], [[14, 0]], pack('e', 1.5))
                ->toPhp($variantType),
        );
        self::assertSame(
            123.45,
            self::variantValue(
                [],
                [],
                [[15, 0]],
                "\x0a\x02" . pack('P', 12345),
            )->toPhp($variantType),
        );
        self::assertSame(
            '18446744073709551615',
            self::variantValue([], [], [[11, 0]], str_repeat("\xff", 8))
                ->toPhp($variantType),
        );
    }

    /**
     * @param list<string>                  $keys
     * @param list<array{0:int|null,1:int}> $children
     * @param list<array{0:int,1:int}>      $values
     */
    private static function variantValue(
        array $keys,
        array $children,
        array $values,
        string $data,
    ): Value {
        $keyValues = [];
        foreach ($keys as $key) {
            $keyValues[] = Value::string($key);
        }

        $childValues = [];
        foreach ($children as [$keysIndex, $valuesIndex]) {
            $childValues[] = Value::struct([
                'keys_index' => $keysIndex === null
                    ? Value::null()
                    : Value::uint($keysIndex),
                'values_index' => Value::uint($valuesIndex),
            ]);
        }

        $valueEntries = [];
        foreach ($values as [$typeId, $byteOffset]) {
            $valueEntries[] = Value::struct([
                'type_id' => Value::uint($typeId),
                'byte_offset' => Value::uint($byteOffset),
            ]);
        }

        return Value::struct([
            'keys' => Value::list($keyValues),
            'children' => Value::list($childValues),
            'values' => Value::list($valueEntries),
            'data' => Value::bytes($data),
        ]);
    }
}
