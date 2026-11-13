<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\Value;
use DuckDB\Quack\Types\VectorType;

final class DecodedVector
{
    /**
     * @param list<Value> $values
     */
    public function __construct(
        public readonly LogicalType $logicalType,
        public readonly VectorType $vectorType,
        public readonly array $values,
    ) {
    }
}
