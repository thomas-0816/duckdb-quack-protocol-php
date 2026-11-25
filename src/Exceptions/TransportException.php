<?php

namespace DuckDB\Quack\Exceptions;

use DuckDB\Quack\Error\QuackError;

/** The HTTP exchange itself failed: no complete reply was received. */
final class TransportException extends QuackError
{
    public function __construct(string $message)
    {
        parent::__construct('quack transport error: ' . $message);
    }

    public function appendMessage(string $message): void
    {
        $this->message = $this->message . $message;
    }
}
