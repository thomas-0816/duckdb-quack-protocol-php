<?php

declare(strict_types=1);

namespace Quack\Protocol\Connection;

/**
 * Connection status shared by a client and its connection.
 *
 * This synchronous port has no background keepalive. State is kept separate
 * so a result stream can report session state while it holds the client's
 * query slot.
 */
final class ConnectionState
{
    private bool $closed = false;

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
