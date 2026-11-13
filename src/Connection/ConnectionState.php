<?php

declare(strict_types=1);

namespace Quack\Protocol\Connection;

/**
 * Connection status shared by a client and its connection.
 *
 * In this synchronous port there is no background heartbeat task; instead a
 * request checks the lease before it is sent. State is still kept separate so
 * a result stream can report session state while it holds the client's query
 * slot.
 */
final class ConnectionState
{
    private float $lastSuccessfulActivity;

    private bool $closed = false;

    public function __construct(?float $now = null)
    {
        $this->lastSuccessfulActivity = $now ?? microtime(true);
    }

    public function recordSuccess(): void
    {
        $this->recordSuccessAt(microtime(true));
    }

    public function recordSuccessAt(float $now): void
    {
        if (!$this->closed) {
            $this->lastSuccessfulActivity = $now;
        }
    }

    public function lastSuccessfulActivity(): float
    {
        return $this->lastSuccessfulActivity;
    }

    public function closeIfExpired(float $timeoutSeconds, ?float $now = null): bool
    {
        $now ??= microtime(true);
        if ($this->closed || $now - $this->lastSuccessfulActivity >= $timeoutSeconds) {
            $this->closed = true;
        }
        return $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }
}
