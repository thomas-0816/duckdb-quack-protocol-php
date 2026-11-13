<?php

namespace DuckDB\Quack\Sql;

use DuckDB\Quack\Exceptions\ProtocolException;

/**
 * Positional or named SQL parameters.
 */
final class SqlParameters
{
    public const POSITIONAL = 'positional';
    public const NAMED = 'named';

    /**
     * @param list<SqlParameter>|array<string,SqlParameter> $values
     */
    private function __construct(
        public readonly string $kind,
        public readonly array $values,
    ) {
    }

    /**
     * @param array<int|string,mixed> $values
     */
    public static function positional(array $values): self
    {
        $normalized = [];
        foreach ($values as $value) {
            $normalized[] = SqlParameter::fromPhp($value);
        }

        return new self(self::POSITIONAL, $normalized);
    }

    /**
     * @param array<string,mixed> $values
     */
    public static function named(array $values): self
    {
        $normalized = [];
        foreach ($values as $name => $value) {
            $normalized[$name] = SqlParameter::fromPhp($value);
        }

        return new self(self::NAMED, $normalized);
    }

    /**
     * Detects positional versus named form from the array keys.
     *
     * @param array<int|string,mixed> $values
     */
    public static function fromArray(array $values): self
    {
        if (array_is_list($values)) {
            return self::positional($values);
        }

        $named = [];
        foreach ($values as $name => $value) {
            if (!is_string($name)) {
                throw new ProtocolException('named SQL parameter keys must be strings');
            }
            $named[$name] = $value;
        }

        return self::named($named);
    }

    /**
     * @param SqlParameters|array<int|string,mixed>|null $params
     */
    public static function coerce(SqlParameters|array|null $params): ?self
    {
        if ($params === null || $params instanceof self) {
            return $params;
        }

        return self::fromArray($params);
    }

    /**
     * @return list<SqlParameter>
     */
    public function positionalValues(): array
    {
        if ($this->kind !== self::POSITIONAL) {
            throw new ProtocolException('SQL parameters are not positional');
        }
        $values = [];
        foreach ($this->values as $value) {
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @return array<string,SqlParameter>
     */
    public function namedValues(): array
    {
        if ($this->kind !== self::NAMED) {
            throw new ProtocolException('SQL parameters are not named');
        }
        $values = [];
        foreach ($this->values as $name => $value) {
            if (!is_string($name)) {
                throw new ProtocolException('named SQL parameter list is invalid');
            }
            $values[$name] = $value;
        }

        return $values;
    }
}
