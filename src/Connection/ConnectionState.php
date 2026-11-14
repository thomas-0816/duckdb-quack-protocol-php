<?php

namespace DuckDB\Quack\Connection;

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

    private bool $busy = false;

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function setBusy(bool $busy): void
    {
        $this->busy = $busy;
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }
}
