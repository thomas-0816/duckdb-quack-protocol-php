<?php

namespace DuckDB\Quack\Sql;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Types\Value;

/**
 * A value or a nested list of values supplied as a SQL parameter.
 *
 * Use {@see self::fromPhp()} when accepting arbitrary PHP values; it detects
 * null, bool, int, float, string, enums, arrays, resources, and objects.
 */
final class SqlParameter
{
    public const VALUE = 'value';
    public const LIST = 'list';

    private function __construct(
        public readonly string $kind,
        public readonly mixed $value,
    ) {
    }

    public static function value(Value $value): self
    {
        return new self(self::VALUE, $value);
    }

    /**
     * @param list<self> $values
     */
    public static function list(array $values): self
    {
        return new self(self::LIST, $values);
    }

    /**
     * Detects the DuckDB type of an arbitrary PHP value.
     *
     * Sequential and string-keyed arrays are JSON-encoded to VARCHAR
     * parameters. Scalars map to their natural DuckDB types, enums map to
     * their backing value or name, resources map to BLOB, Stringable objects
     * map to VARCHAR, and other objects are JSON-encoded to VARCHAR.
     *
     * Use {@see self::list()} to force a DuckDB LIST literal instead.
     */
    public static function fromPhp(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_array($value)) {
            try {
                return self::value(Value::string(json_encode($value, JSON_THROW_ON_ERROR)));
            } catch (\JsonException $error) {
                throw new ProtocolException('could not JSON-encode SQL array parameter', previous: $error);
            }
        }
        if ($value instanceof Value) {
            return self::value($value);
        }
        if ($value === null) {
            return self::value(Value::null());
        }
        if (is_bool($value)) {
            return self::value(Value::bool($value));
        }
        if (is_int($value)) {
            return self::value(Value::int($value));
        }
        if (is_float($value)) {
            return self::value(Value::double($value));
        }
        if (is_string($value)) {
            return self::value(Value::string($value));
        }
        if ($value instanceof \BackedEnum) {
            return self::value(is_int($value->value) ? Value::int($value->value) : Value::string($value->value));
        }
        if ($value instanceof \UnitEnum) {
            return self::value(Value::string($value->name));
        }
        if ($value instanceof \Stringable) {
            return self::value(Value::string((string) $value));
        }
        if (is_resource($value)) {
            $contents = stream_get_contents($value);
            if ($contents === false) {
                throw new ProtocolException('could not read resource SQL parameter');
            }

            return self::value(Value::bytes($contents));
        }
        if (is_object($value)) {
            try {
                return self::value(Value::string(json_encode($value, JSON_THROW_ON_ERROR)));
            } catch (\JsonException $error) {
                throw new ProtocolException('could not JSON-encode SQL parameter of type ' . get_debug_type($value), previous: $error);
            }
        }

        throw new ProtocolException('unsupported SQL parameter type ' . get_debug_type($value));
    }

    public function asValue(): Value
    {
        if (!$this->value instanceof Value) {
            throw new ProtocolException('SQL parameter is not a value parameter');
        }

        return $this->value;
    }

    /**
     * @return list<self>
     */
    public function asList(): array
    {
        if (!is_array($this->value)) {
            throw new ProtocolException('SQL parameter is not a list parameter');
        }
        $values = [];
        foreach ($this->value as $item) {
            if (!$item instanceof self) {
                throw new ProtocolException('SQL list parameter contains an invalid element');
            }
            $values[] = $item;
        }

        return $values;
    }
}
