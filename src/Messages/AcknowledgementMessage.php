<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Types\HugeIntParts;

/**
 * Tells the server that the client has the whole result for a query, so the
 * server may drop what it kept for a replay. A zero uuid acknowledges every
 * result on the connection.
 */
final class AcknowledgementMessage extends QuackMessage
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
