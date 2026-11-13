<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Types\HugeIntParts;
use DuckDB\Quack\Types\LogicalType;

final class PrepareResponseMessage extends QuackMessage
{
    /**
     * @param list<LogicalType> $resultTypes
     * @param list<string>      $resultNames
     * @param list<DataChunk>   $results
     */
    public function __construct(
        public readonly MessageHeader $header,
        public readonly array $resultTypes,
        public readonly array $resultNames,
        public readonly bool $needsMoreFetch,
        public readonly array $results,
        public readonly HugeIntParts $resultUuid,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
