<?php

namespace DuckDB\Quack\Exceptions;

use DuckDB\Quack\Error\QuackError;

/** A wire string was not valid UTF-8. */
final class Utf8Exception extends QuackError
{
    public function __construct(string $message)
    {
        parent::__construct('utf-8 error: ' . $message);
    }
}
