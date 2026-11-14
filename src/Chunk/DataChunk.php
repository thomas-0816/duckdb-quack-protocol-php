<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Types\LogicalType;

final class DataChunk
{
    /**
     * @param list<LogicalType>   $types
     * @param list<DecodedVector> $columns
     * @param list<LogicalType>   $resultTypes
     * @param list<string>        $resultNames
     */
    public function __construct(
        public int $rowCount,
        public array $types,
        public array $columns,
        public array $resultTypes = [],
        public array $resultNames = [],
    ) {
    }
}
