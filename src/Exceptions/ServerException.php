<?php

declare(strict_types=1);

namespace Quack\Protocol\Exceptions;

use Quack\Protocol\Error\QuackError;
use Quack\Protocol\Error\ServerError;

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

    public function isConnectionFatal(): bool
    {
        return $this->serverError->isFatal();
    }
}
