<?php

namespace DuckDB\Quack\Exceptions;

use DuckDB\Quack\Error\QuackError;

/** A framing, codec, or protocol-state error. */
final class ProtocolException extends QuackError
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct('quack protocol error: ' . $message, 0, $previous);
    }
}
