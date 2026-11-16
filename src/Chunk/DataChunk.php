<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Types\LogicalType;

final class DataChunk
{
    /**
     * @param list<DecodedVector> $columns
     * @param list<string> $columnNames
     * @param list<LogicalType> $columnTypes
     */
    public function __construct(
        public int $rowCount,
        public array $columns,
        public array $columnNames,
        public array $columnTypes,
    ) {
    }
}
