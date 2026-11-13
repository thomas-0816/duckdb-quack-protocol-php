<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\Value;

final class ColumnInput
{
    /**
     * @param list<Value> $values
     */
    public function __construct(
        public readonly ?string $name,
        public readonly LogicalType $logicalType,
        public readonly array $values,
    ) {
    }
}
