<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Types\LogicalType;

final class DataChunk
{
    /**
     * @param list<LogicalType>  $types
     * @param list<DecodedVector> $columns
     * @param list<string>|null   $columnNames
     */
    public function __construct(
        public int $rowCount,
        public array $types,
        public array $columns,
        public ?array $columnNames = null,
    ) {
    }
}
