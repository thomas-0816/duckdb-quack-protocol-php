<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;

/**
 * A decoded Quack value.
 *
 * The tagged union mirrors the Rust SDK. Large integers are `int|string` so
 * values outside PHP's signed 64-bit range stay exact.
 */
final class Value
{
    public const NULL = 'null';
    public const BOOL = 'bool';
    public const INT = 'int';
    public const UINT = 'uint';
    public const HUGEINT = 'hugeint';
    public const UHUGEINT = 'uhugeint';
    public const FLOAT = 'float';
    public const DOUBLE = 'double';
    public const STRING = 'string';
    public const BYTES = 'bytes';
    public const DECIMAL = 'decimal';
    public const DATE = 'date';
    public const TIME = 'time';
    public const TIME_TZ = 'time_tz';
    public const TIMESTAMP = 'timestamp';
    public const INTERVAL = 'interval';
    public const LIST = 'list';
    public const STRUCT = 'struct';

    private function __construct(
        public readonly string $kind,
        public readonly mixed $value = null,
    ) {
    }

    public static function null(): self
    {
        return new self(self::NULL);
    }

    public static function bool(bool $value): self
    {
        return new self(self::BOOL, $value);
    }

    public static function int(int $value): self
    {
        return new self(self::INT, $value);
    }

    public static function uint(int|string $value): self
    {
        return new self(self::UINT, $value);
    }

    public static function hugeInt(int|string $value): self
    {
        return new self(self::HUGEINT, $value);
    }

    public static function uHugeInt(int|string $value): self
    {
        return new self(self::UHUGEINT, $value);
    }

    public static function float(float $value): self
    {
        return new self(self::FLOAT, $value);
    }

    public static function double(float $value): self
    {
        return new self(self::DOUBLE, $value);
    }

    public static function string(string $value): self
    {
        return new self(self::STRING, $value);
    }

    public static function bytes(string $value): self
    {
        return new self(self::BYTES, $value);
    }

    public static function decimal(DecimalValue $value): self
    {
        return new self(self::DECIMAL, $value);
    }

    public static function date(DateValue $value): self
    {
        return new self(self::DATE, $value);
    }

    public static function time(TimeValue $value): self
    {
        return new self(self::TIME, $value);
    }

    public static function timeTz(TimeTzValue $value): self
    {
        return new self(self::TIME_TZ, $value);
    }

    public static function timestamp(TimestampValue $value): self
    {
        return new self(self::TIMESTAMP, $value);
    }

    public static function interval(IntervalValue $value): self
    {
        return new self(self::INTERVAL, $value);
    }

    /**
     * @param list<Value> $values
     */
    public static function list(array $values): self
    {
        return new self(self::LIST, $values);
    }

    /**
     * @param array<string,Value> $fields
     */
    public static function struct(array $fields): self
    {
        return new self(self::STRUCT, $fields);
    }

    public function isNull(): bool
    {
        return $this->kind === self::NULL;
    }

    public function isList(): bool
    {
        return $this->kind === self::LIST;
    }

    public function isStruct(): bool
    {
        return $this->kind === self::STRUCT;
    }

    /**
     * Converts this decoded value to a native PHP value.
     *
     * Integers outside PHP's signed range stay as decimal strings. Lists and
     * structs become arrays, and temporal/decimal values become readable
     * strings unless they fit safely in a PHP float. When the logical type is
     * available, MAP values become associative arrays instead of lists of
     * key/value structs.
     */
    public function toPhp(?LogicalType $type = null): mixed
    {
        if ($type !== null && $type->id === LogicalTypeId::Map && $this->kind === self::LIST) {
            return self::mapToPhp($this->asList(), $type);
        }
        if ($type !== null && self::isJsonType($type) && $this->kind === self::STRING) {
            return self::jsonToPhp($this->asString());
        }
        if ($type !== null && $type->id === LogicalTypeId::Union && $this->kind === self::STRUCT) {
            return self::unionToPhp($this->asStruct(), $type);
        }
        if ($type !== null && $type->id === LogicalTypeId::Variant && $this->kind === self::STRUCT) {
            return VariantDecoder::fromValue($this)->decode();
        }

        return match ($this->kind) {
            self::NULL => null,
            self::BOOL => $this->asBool(),
            self::INT, self::UINT, self::HUGEINT, self::UHUGEINT => $this->asIntOrString(),
            self::FLOAT, self::DOUBLE => $this->asFloat(),
            self::STRING => $this->asString(),
            self::BYTES => $this->asBytes(),
            self::DECIMAL => $this->asDecimal()->toPhp(),
            self::DATE => TemporalFormatter::formatDate($this->asDate()->days),
            self::TIME => TemporalFormatter::formatTime(
                $this->asTime()->value,
                $this->asTime()->unit,
            ),
            self::TIME_TZ => TemporalFormatter::formatTimeTz($this->asTimeTz()->bits),
            self::TIMESTAMP => TemporalFormatter::formatTimestamp(
                $this->asTimestamp()->value,
                $this->asTimestamp()->unit,
                $this->asTimestamp()->timezoneUtc,
            ),
            self::INTERVAL => TemporalFormatter::formatInterval($this->asInterval()),
            self::LIST => array_map(
                static fn (Value $item): mixed => $item->toPhp(self::childType($type)),
                $this->asList(),
            ),
            self::STRUCT => self::structToPhp($this->asStruct(), $type),
            default => throw new ProtocolException(
                "cannot convert value kind {$this->kind} to PHP",
            ),
        };
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->value == $other->value;
    }

    public function asBool(): bool
    {
        if ($this->kind !== self::BOOL || !is_bool($this->value)) {
            throw $this->kindMismatch('boolean');
        }

        return $this->value;
    }

    public function asInt(): int
    {
        if ($this->kind !== self::INT || !is_int($this->value)) {
            throw $this->kindMismatch('integer');
        }

        return $this->value;
    }

    /**
     * Integer, unsigned, huge, and unsigned-huge values. Values outside PHP's
     * signed range stay as canonical decimal strings.
     */
    public function asIntOrString(): int|string
    {
        $valid = in_array($this->kind, [self::INT, self::UINT, self::HUGEINT, self::UHUGEINT], true)
            && (is_int($this->value) || is_string($this->value));
        if (!$valid) {
            throw $this->kindMismatch('integer');
        }

        /** @var int|string $result */
        $result = $this->value;

        return $result;
    }

    public function asFloat(): float
    {
        $valid = in_array($this->kind, [self::FLOAT, self::DOUBLE], true)
            && is_float($this->value);
        if (!$valid) {
            throw $this->kindMismatch('float');
        }

        /** @var float $result */
        $result = $this->value;

        return $result;
    }

    public function asString(): string
    {
        if ($this->kind !== self::STRING || !is_string($this->value)) {
            throw $this->kindMismatch('string');
        }

        return $this->value;
    }

    public function asBytes(): string
    {
        if ($this->kind !== self::BYTES || !is_string($this->value)) {
            throw $this->kindMismatch('bytes');
        }

        return $this->value;
    }

    public function asDecimal(): DecimalValue
    {
        if (!$this->value instanceof DecimalValue) {
            throw $this->kindMismatch('decimal');
        }

        return $this->value;
    }

    public function asDate(): DateValue
    {
        if (!$this->value instanceof DateValue) {
            throw $this->kindMismatch('date');
        }

        return $this->value;
    }

    public function asTime(): TimeValue
    {
        if (!$this->value instanceof TimeValue) {
            throw $this->kindMismatch('time');
        }

        return $this->value;
    }

    public function asTimeTz(): TimeTzValue
    {
        if (!$this->value instanceof TimeTzValue) {
            throw $this->kindMismatch('time with time zone');
        }

        return $this->value;
    }

    public function asTimestamp(): TimestampValue
    {
        if (!$this->value instanceof TimestampValue) {
            throw $this->kindMismatch('timestamp');
        }

        return $this->value;
    }

    public function asInterval(): IntervalValue
    {
        if (!$this->value instanceof IntervalValue) {
            throw $this->kindMismatch('interval');
        }

        return $this->value;
    }

    /**
     * @return list<Value>
     */
    public function asList(): array
    {
        if ($this->kind !== self::LIST || !is_array($this->value)) {
            throw $this->kindMismatch('list');
        }
        $items = [];
        foreach ($this->value as $item) {
            if (!$item instanceof self) {
                throw new ProtocolException('list value contains a non-Value element');
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array<string,Value>
     */
    public function asStruct(): array
    {
        if ($this->kind !== self::STRUCT || !is_array($this->value)) {
            throw $this->kindMismatch('struct');
        }
        $fields = [];
        foreach ($this->value as $name => $item) {
            if (!is_string($name) || !$item instanceof self) {
                throw new ProtocolException('struct value contains an invalid field');
            }
            $fields[$name] = $item;
        }

        return $fields;
    }

    private function kindMismatch(string $expected): ProtocolException
    {
        return new ProtocolException(
            "expected {$expected} value, got {$this->kind}",
        );
    }

    /**
     * @param array<string,Value> $fields
     * @return array<string,mixed>
     */
    private static function structToPhp(array $fields, ?LogicalType $type = null): array
    {
        $childTypes = [];
        if ($type !== null && $type->id === LogicalTypeId::Struct) {
            foreach (LogicalTypeCodec::getStructChildren($type) as $child) {
                $childTypes[$child->name] = $child->logicalType;
            }
        }

        $result = [];
        foreach ($fields as $name => $value) {
            $result[$name] = $value->toPhp($childTypes[$name] ?? null);
        }

        return $result;
    }

    /**
     * @param array<string,Value> $fields
     * @return array<string,mixed>
     */
    private static function unionToPhp(array $fields, LogicalType $type): array
    {
        $tagValue = $fields[''] ?? null;
        if ($tagValue === null) {
            throw new ProtocolException('UNION value is missing its tag');
        }
        $tag = $tagValue->asIntOrString();
        if (!is_int($tag)) {
            if (preg_match('/^[0-9]+$/', $tag) !== 1) {
                throw new ProtocolException("invalid UNION tag {$tag}");
            }
            $tag = (int) $tag;
        }

        $children = LogicalTypeCodec::getStructChildren($type);
        $member = $children[$tag + 1] ?? null;
        if ($member === null) {
            throw new ProtocolException("UNION tag {$tag} is out of range");
        }

        return [
            $member->name => ($fields[$member->name] ?? Value::null())
                ->toPhp($member->logicalType),
        ];
    }

    private static function childType(?LogicalType $type): ?LogicalType
    {
        if ($type === null) {
            return null;
        }

        return match ($type->id) {
            LogicalTypeId::List, LogicalTypeId::Array => LogicalTypeCodec::getChildType($type),
            default => null,
        };
    }

    private static function isJsonType(LogicalType $type): bool
    {
        return $type->id === LogicalTypeId::Varchar
            && strcasecmp($type->alias() ?? '', 'JSON') === 0;
    }

    private static function jsonToPhp(string $json): mixed
    {
        try {
            return json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (\JsonException $error) {
            // DuckDB writes non-finite doubles as bare tokens, which are not
            // valid JSON but are returned verbatim by PDO_duckdb.
            $token = trim($json);
            if ($token === 'NaN' || $token === 'Infinity' || $token === '-Infinity') {
                return $token;
            }
            throw new ProtocolException(
                'invalid JSON value: ' . $error->getMessage(),
            );
        }
    }

    /**
     * Converts a decoded MAP key to a PHP array key.
     *
     * List keys are flattened and joined with ", " like PDO_duckdb does; PHP
     * arrays cannot be used as keys directly.
     */
    public static function mapKeyToPhp(mixed $key): int|string
    {
        if (is_int($key) || is_string($key)) {
            return $key;
        }
        return self::mapKeyToString($key);
    }

    private static function mapKeyToString(mixed $value): string
    {
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $element) {
                $parts[] = self::mapKeyToString($element);
            }
            return implode(', ', $parts);
        }
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }
        throw new ProtocolException(
            'MAP key cannot be represented as a PHP array key',
        );
    }

    /**
     * @param list<Value> $pairs
     * @return array<int|string,mixed>
     */
    private static function mapToPhp(array $pairs, LogicalType $type): array
    {
        $pairType = LogicalTypeCodec::getChildType($type);
        $keyType = null;
        $valueType = null;
        foreach (LogicalTypeCodec::getStructChildren($pairType) as $child) {
            if ($child->name === 'key') {
                $keyType = $child->logicalType;
            } elseif ($child->name === 'value') {
                $valueType = $child->logicalType;
            }
        }

        $result = [];
        foreach ($pairs as $pair) {
            $fields = $pair->asStruct();
            $key = self::mapKeyToPhp(($fields['key'] ?? Value::null())->toPhp($keyType));
            $result[$key] = ($fields['value'] ?? Value::null())->toPhp($valueType);
        }

        return $result;
    }
}
