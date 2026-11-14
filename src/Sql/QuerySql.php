<?php

namespace DuckDB\Quack\Sql;

/**
 * A raw SQL string.
 */
final class QuerySql
{
    public readonly string $raw;

    public function __construct(string $raw)
    {
        $this->raw = $raw;
    }

    public function asString(): string
    {
        return $this->raw;
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
