<?php

namespace DuckDB\Quack\Exceptions;

use DuckDB\Quack\Error\QuackError;

/** A value or logical type this implementation cannot encode or decode. */
final class UnsupportedTypeException extends QuackError
{
    public function __construct(string $message)
    {
        parent::__construct('unsupported quack type: ' . $message);
    }
}
