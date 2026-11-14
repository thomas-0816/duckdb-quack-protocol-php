<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Types\VectorType;

final class DecodedVector
{
    /**
     * @param list<mixed> $values
     */
    public function __construct(
        public readonly VectorType $vectorType,
        public readonly array $values,
    ) {
    }
}
