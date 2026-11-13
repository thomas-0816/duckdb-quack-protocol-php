<?php

namespace DuckDB\Quack\Messages;

use DuckDB\Quack\Error\ServerError;

final class ErrorMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly ServerError $error,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
