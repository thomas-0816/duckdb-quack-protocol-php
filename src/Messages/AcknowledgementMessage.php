<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Types\HugeIntParts;

/**
 * Tells the server that the client has the whole result for a query, so the
 * server may drop what it kept for a replay.
 *
 * The query uuid must match the cached result exactly. A zero uuid is not a
 * wildcard: it only succeeds when the connection has no cached result.
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
