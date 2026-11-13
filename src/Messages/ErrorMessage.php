<?php

namespace Quack\Protocol\Messages;

use Quack\Protocol\Error\ServerError;

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
