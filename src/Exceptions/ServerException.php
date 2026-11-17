<?php

namespace DuckDB\Quack\Exceptions;

use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Error\ServerError;

/** A structured error returned by the server. */
final class ServerException extends QuackError
{
    public function __construct(private readonly ServerError $serverError)
    {
        parent::__construct('quack server error: ' . $serverError->message());
    }

    public function serverError(): ServerError
    {
        return $this->serverError;
    }
}
