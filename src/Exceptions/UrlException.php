<?php

namespace DuckDB\Quack\Exceptions;

use DuckDB\Quack\Error\QuackError;

/** A malformed Quack URI. */
final class UrlException extends QuackError
{
    public function __construct(string $message)
    {
        parent::__construct('url parse error: ' . $message);
    }
}
