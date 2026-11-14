<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Chunk\DataChunk;

final class FetchResponseMessage extends QuackMessage
{
    /**
     * @param list<DataChunk> $results
     */
    public function __construct(
        public readonly MessageHeader $header,
        public readonly array $results,
        public readonly ?int $totalBatches = null,
        public readonly ?int $batchIndex = null,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
