<?php

namespace Quack\Protocol\Chunk;

use Quack\Protocol\Types\LogicalType;
use Quack\Protocol\Types\Value;

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

    /**
     * @return list<Value>|null
     */
    public function columnValues(int $column): ?array
    {
        return isset($this->columns[$column]) ? $this->columns[$column]->values : null;
    }
}
