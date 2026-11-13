<?php

namespace Quack\Protocol\Tests\Unit\Json;

use PHPUnit\Framework\TestCase;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Json\BigIntJsonMode;
use Quack\Protocol\Json\BytesJsonMode;
use Quack\Protocol\Json\JsonConverter;
use Quack\Protocol\Json\JsonOptions;
use Quack\Protocol\Json\TaggedJsonMode;
use Quack\Protocol\Types\DateValue;
use Quack\Protocol\Types\DecimalValue;
use Quack\Protocol\Types\IntervalValue;
use Quack\Protocol\Types\TimestampUnit;
use Quack\Protocol\Types\TimestampValue;
use Quack\Protocol\Types\TimeUnit;
use Quack\Protocol\Types\TimeValue;
use Quack\Protocol\Types\Value;

final class JsonConverterTest extends TestCase
{
    public function testDefaultModesPreservePrecisionAndUseReadableStrings(): void
    {
        $value = Value::hugeInt('170141183460469231731687303715884105727');
        self::assertSame(
            '170141183460469231731687303715884105727',
            JsonConverter::toJsonValue($value, new JsonOptions()),
        );

        self::assertSame(
            '12.34',
            JsonConverter::toJsonValue(
                Value::decimal(new DecimalValue('1234', 10, 2)),
                new JsonOptions(),
            ),
        );
        self::assertSame(
            '1970-01-02',
            JsonConverter::toJsonValue(Value::date(new DateValue(1)), new JsonOptions()),
        );
        self::assertSame(
            '00:00:01.000001',
            JsonConverter::toJsonValue(
                Value::time(new TimeValue(TimeUnit::Micros, 1_000_001)),
                new JsonOptions(),
            ),
        );
        self::assertSame(
            '1970-01-01T00:00:01Z',
            JsonConverter::toJsonValue(
                Value::timestamp(new TimestampValue(TimestampUnit::Seconds, 1)),
                new JsonOptions(),
            ),
        );
        self::assertSame(
            '1970-01-01T00:00:01.000001Z',
            JsonConverter::toJsonValue(
                Value::timestamp(new TimestampValue(TimestampUnit::Micros, 1_000_001, true)),
                new JsonOptions(),
            ),
        );
        self::assertSame(
            ['kind' => 'interval', 'months' => 1, 'days' => 2, 'micros' => '3'],
            JsonConverter::toJsonValue(
                Value::interval(new IntervalValue(1, 2, 3)),
                new JsonOptions(),
            ),
        );
    }

    public function testContainersAreConvertedRecursively(): void
    {
        $value = Value::struct([
            'items' => Value::list([Value::int(1), Value::null()]),
            'nested' => Value::struct(['ok' => Value::bool(true)]),
        ]);

        self::assertSame(
            ['items' => ['1', null], 'nested' => ['ok' => true]],
            JsonConverter::toJsonValue($value, new JsonOptions()),
        );
    }

    public function testBytesModes(): void
    {
        $value = Value::bytes("\x00\xff");

        self::assertSame(
            'AP8=',
            JsonConverter::toJsonValue($value, new JsonOptions()),
        );
        self::assertSame(
            '00ff',
            JsonConverter::toJsonValue(
                $value,
                new JsonOptions(bytes: BytesJsonMode::Hex),
            ),
        );
        self::assertSame(
            [0, 255],
            JsonConverter::toJsonValue(
                $value,
                new JsonOptions(bytes: BytesJsonMode::Array),
            ),
        );
    }

    public function testTaggedModesKeepDecodedRepresentation(): void
    {
        $options = new JsonOptions(
            decimal: TaggedJsonMode::Tagged,
            date: TaggedJsonMode::Tagged,
            time: TaggedJsonMode::Tagged,
            timestamp: TaggedJsonMode::Tagged,
        );

        self::assertSame(
            ['kind' => 'decimal', 'value' => '1234', 'width' => 10, 'scale' => 2],
            JsonConverter::toJsonValue(Value::decimal(new DecimalValue('1234', 10, 2)), $options),
        );
        self::assertSame(
            ['kind' => 'date', 'days' => 1],
            JsonConverter::toJsonValue(Value::date(new DateValue(1)), $options),
        );
        self::assertSame(
            ['kind' => 'time', 'unit' => 'micros', 'value' => '1000001'],
            JsonConverter::toJsonValue(Value::time(new TimeValue(TimeUnit::Micros, 1_000_001)), $options),
        );
        self::assertSame(
            ['kind' => 'timestamp', 'unit' => 'seconds', 'value' => '1', 'timezone' => 'utc'],
            JsonConverter::toJsonValue(
                Value::timestamp(new TimestampValue(TimestampUnit::Seconds, 1, true)),
                $options,
            ),
        );
    }

    public function testNumberModeAllowsValuesInsidePhpRange(): void
    {
        self::assertSame(
            42,
            JsonConverter::toJsonValue(
                Value::int(42),
                new JsonOptions(bigint: BigIntJsonMode::Number),
            ),
        );
    }

    public function testNumberModeRejectsValuesOutsidePhpRange(): void
    {
        $this->expectException(ProtocolException::class);

        JsonConverter::toJsonValue(
            Value::hugeInt('170141183460469231731687303715884105727'),
            new JsonOptions(bigint: BigIntJsonMode::Number),
        );
    }

    public function testRowsAndRowListsAreConverted(): void
    {
        $row = ['n' => Value::int(1), 's' => Value::string('x')];

        self::assertSame(
            ['n' => '1', 's' => 'x'],
            JsonConverter::toJsonRow($row, new JsonOptions()),
        );
        self::assertSame(
            [['n' => '1', 's' => 'x'], ['n' => '2', 's' => 'y']],
            JsonConverter::toJsonRows([
                $row,
                ['n' => Value::int(2), 's' => Value::string('y')],
            ], new JsonOptions()),
        );
    }
}
