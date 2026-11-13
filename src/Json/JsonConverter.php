<?php

namespace Quack\Protocol\Json;

use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Types\BigInt;
use Quack\Protocol\Types\TemporalFormatter;
use Quack\Protocol\Types\TimestampUnit;
use Quack\Protocol\Types\TimeUnit;
use Quack\Protocol\Types\Value;

/**
 * Converts decoded values to structures suitable for json_encode().
 */
final class JsonConverter
{
    public static function toJsonValue(Value $value, JsonOptions $options): mixed
    {
        return match ($value->kind) {
            Value::NULL => null,
            Value::BOOL => $value->asBool(),
            Value::INT => self::bigintToJson(
                BigInt::fromInt($value->asInt()),
                $options->bigint,
            ),
            Value::UINT => self::unsignedBigintToJson(
                BigInt::coerce($value->asIntOrString()),
                $options->bigint,
            ),
            Value::HUGEINT => self::bigintToJson(
                BigInt::coerce($value->asIntOrString()),
                $options->bigint,
            ),
            Value::UHUGEINT => self::unsignedBigintToJson(
                BigInt::coerce($value->asIntOrString()),
                $options->bigint,
            ),
            Value::FLOAT, Value::DOUBLE => self::floatToJson($value->asFloat()),
            Value::STRING => $value->asString(),
            Value::BYTES => self::bytesToJson($value->asBytes(), $options->bytes),
            Value::DECIMAL => $options->decimal === TaggedJsonMode::Tagged
                ? [
                    'kind' => 'decimal',
                    'value' => (string) $value->asDecimal()->value,
                    'width' => $value->asDecimal()->width,
                    'scale' => $value->asDecimal()->scale,
                ]
                : $value->asDecimal()->toString(),
            Value::DATE => $options->date === TaggedJsonMode::Tagged
                ? ['kind' => 'date', 'days' => $value->asDate()->days]
                : self::dateToIso($value->asDate()->days),
            Value::TIME => $options->time === TaggedJsonMode::Tagged
                ? [
                    'kind' => 'time',
                    'unit' => $value->asTime()->unit === TimeUnit::Micros ? 'micros' : 'nanos',
                    'value' => (string) $value->asTime()->value,
                ]
                : self::timeToString($value->asTime()->value, $value->asTime()->unit),
            Value::TIME_TZ => [
                'kind' => 'time_tz',
                'bits' => (string) $value->asTimeTz()->bits,
            ],
            Value::TIMESTAMP => self::timestampJson($value->asTimestamp(), $options->timestamp),
            Value::INTERVAL => [
                'kind' => 'interval',
                'months' => $value->asInterval()->months,
                'days' => $value->asInterval()->days,
                'micros' => (string) $value->asInterval()->micros,
            ],
            Value::LIST => array_map(
                static fn (Value $item): mixed => self::toJsonValue($item, $options),
                $value->asList(),
            ),
            Value::STRUCT => self::toJsonObject($value->asStruct(), $options),
            default => throw new ProtocolException(
                "cannot convert value kind {$value->kind} to JSON",
            ),
        };
    }

    private static function floatToJson(float $value): ?float
    {
        return is_finite($value) ? $value : null;
    }

    /**
     * @param array<string,Value> $row
     * @return array<string,mixed>
     */
    public static function toJsonRow(array $row, JsonOptions $options): array
    {
        return self::toJsonObject($row, $options);
    }

    /**
     * @param list<array<string,Value>> $rows
     * @return list<array<string,mixed>>
     */
    public static function toJsonRows(array $rows, JsonOptions $options): array
    {
        return array_map(
            static fn (array $row): array => self::toJsonRow($row, $options),
            $rows,
        );
    }

    /**
     * @param array<string,Value> $row
     * @return array<string,mixed>
     */
    private static function toJsonObject(array $row, JsonOptions $options): array
    {
        $object = [];
        foreach ($row as $key => $value) {
            $object[$key] = self::toJsonValue($value, $options);
        }
        return $object;
    }

    private static function bigintToJson(BigInt $value, BigIntJsonMode $mode): int|string
    {
        if ($mode === BigIntJsonMode::String) {
            return $value->__toString();
        }
        if ($value->compare(BigInt::fromInt(PHP_INT_MIN)) < 0
            || $value->compare(BigInt::fromInt(PHP_INT_MAX)) > 0) {
            throw new ProtocolException(
                "bigint value {$value} exceeds JSON number range",
            );
        }
        return $value->toInt();
    }

    private static function unsignedBigintToJson(BigInt $value, BigIntJsonMode $mode): int|string
    {
        if ($mode === BigIntJsonMode::String) {
            return $value->__toString();
        }
        if ($value->compare(BigInt::fromInt(PHP_INT_MAX)) > 0) {
            throw new ProtocolException(
                "bigint value {$value} exceeds JSON number range",
            );
        }
        return $value->toInt();
    }

    /**
     * @return string|list<int>
     */
    private static function bytesToJson(string $value, BytesJsonMode $mode): string|array
    {
        return match ($mode) {
            BytesJsonMode::Base64 => base64_encode($value),
            BytesJsonMode::Hex => bin2hex($value),
            BytesJsonMode::Array => self::bytesToIntList($value),
        };
    }

    /**
     * @return list<int>
     */
    private static function bytesToIntList(string $value): array
    {
        $unpacked = unpack('C*', $value);
        if ($unpacked === false) {
            throw new ProtocolException('byte string could not be unpacked');
        }
        $bytes = [];
        foreach ($unpacked as $byte) {
            if (!is_int($byte)) {
                throw new ProtocolException('byte string contained a non-integer');
            }
            $bytes[] = $byte;
        }

        return $bytes;
    }

    /**
     * @return array<string,string>|string
     */
    private static function timestampJson(
        \Quack\Protocol\Types\TimestampValue $value,
        TaggedJsonMode $mode,
    ): array|string {
        if ($mode === TaggedJsonMode::Tagged) {
            $unit = match ($value->unit) {
                TimestampUnit::Seconds => 'seconds',
                TimestampUnit::Millis => 'millis',
                TimestampUnit::Micros => 'micros',
                TimestampUnit::Nanos => 'nanos',
            };
            $result = [
                'kind' => 'timestamp',
                'unit' => $unit,
                'value' => (string) $value->value,
            ];
            if ($value->timezoneUtc) {
                $result['timezone'] = 'utc';
            }
            return $result;
        }
        return self::timestampToIso($value->value, $value->unit);
    }

    private static function dateToIso(int $days): string
    {
        return TemporalFormatter::formatDate($days);
    }

    private static function timeToString(int $value, TimeUnit $unit): string
    {
        return TemporalFormatter::formatTime($value, $unit);
    }

    private static function timestampToIso(int $value, TimestampUnit $unit): string
    {
        return TemporalFormatter::formatTimestampIso($value, $unit);
    }
}
