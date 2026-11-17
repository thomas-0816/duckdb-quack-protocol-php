<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Types\HugeIntParts;

final class PrepareRequestMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly string $sql,
        public readonly HugeIntParts $queryUuid,
        public readonly int|string|null $inlineRows = null,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
