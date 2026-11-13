<?php

declare(strict_types=1);

namespace Quack\Protocol\Exceptions;

use Quack\Protocol\Error\QuackError;

/**
 * The HTTP request got a non-success reply.
 *
 * The body is raw and untrusted, is never printed by this class, and is empty
 * when it was larger than 16 KiB.
 */
final class HttpStatusException extends QuackError
{
    public function __construct(
        private readonly int $status,
        private readonly string $body,
    ) {
        parent::__construct("quack HTTP request failed with status {$status}");
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function httpStatus(): array
    {
        return [$this->status, $this->body];
    }

    public function isConnectionFatal(): bool
    {
        return true;
    }
}
