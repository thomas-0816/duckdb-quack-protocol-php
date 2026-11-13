<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Types\HugeIntParts;

final class CancelMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly HugeIntParts $queryUuid,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
