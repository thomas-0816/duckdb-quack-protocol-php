<?php

namespace DuckDB\Quack\Messages;

final class SuccessResponseMessage extends QuackMessage
{
    public function __construct(public readonly MessageHeader $header)
    {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
