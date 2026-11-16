<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Types\HugeIntParts;

/**
 * Cancels the query currently running on the connection.
 *
 * A zero uuid cancels whatever is active; a non-zero uuid must match the
 * running query or the server rejects the request.
 */
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
