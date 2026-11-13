<?php

declare(strict_types=1);

namespace Quack\Protocol\Messages;

use Quack\Protocol\Chunk\DataChunk;

final class FetchResponseMessage extends QuackMessage
{
    /**
     * @param list<DataChunk> $results
     */
    public function __construct(
        public readonly MessageHeader $header,
        public readonly array $results,
        public readonly int|string|null $totalBatches = null,
        public readonly int|string|null $batchIndex = null,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
