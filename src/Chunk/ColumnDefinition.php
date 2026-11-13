<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Types\LogicalType;

final class ColumnDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly LogicalType $logicalType,
    ) {
    }
}
