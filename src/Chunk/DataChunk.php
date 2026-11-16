<?php

namespace DuckDB\Quack\Chunk;

final class DataChunk
{
    /**
     * @param list<DecodedVector> $columns
     * @param list<string> $columnNames
     */
    public function __construct(
        public int $rowCount,
        public array $columns,
        public array $columnNames,
    ) {
    }
}
