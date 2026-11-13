<?php

namespace DuckDB\Quack\Exceptions;

use DuckDB\Quack\Error\QuackError;
use DuckDB\Quack\Error\TransportErrorKind;

/** The HTTP exchange itself failed: no complete reply was received. */
final class TransportException extends QuackError
{
    public function __construct(
        private readonly TransportErrorKind $kind,
        string $detail,
    ) {
        parent::__construct("quack transport error ({$kind->value}): {$detail}");
    }

    public function kind(): TransportErrorKind
    {
        return $this->kind;
    }

    public function isConnectionFatal(): bool
    {
        return true;
    }
}
