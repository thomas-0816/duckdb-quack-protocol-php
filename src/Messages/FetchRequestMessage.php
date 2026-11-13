<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Types\HugeIntParts;

final class FetchRequestMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly HugeIntParts $resultUuid,
        public readonly int|string|null $batchIndex = null,
        public readonly int|string|null $ackIndex = null,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
