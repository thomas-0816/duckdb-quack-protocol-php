<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Chunk\DataChunk;
use DuckDB\Quack\Types\HugeIntParts;

final class PrepareResponseMessage extends QuackMessage
{
    /**
     * @param list<DataChunk> $results
     */
    public function __construct(
        public readonly MessageHeader $header,
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
