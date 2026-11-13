<?php

declare(strict_types=1);

namespace Quack\Protocol\Exceptions;

use Quack\Protocol\Error\QuackError;

/** A framing, codec, or protocol-state error. */
final class ProtocolException extends QuackError
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct('quack protocol error: ' . $message, 0, $previous);
    }

    public function isConnectionFatal(): bool
    {
        return true;
    }
}
