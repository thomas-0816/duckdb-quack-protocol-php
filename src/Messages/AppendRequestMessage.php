<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Chunk\DataChunk;

final class AppendRequestMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly ?string $schemaName,
        public readonly string $tableName,
        public readonly DataChunk $appendChunk,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
