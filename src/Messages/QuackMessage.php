<?php

namespace DuckDB\Quack\Messages;

/**
 * Base type for every protocol message.
 */
abstract class QuackMessage
{
    abstract public function header(): MessageHeader;

    public function messageType(): MessageType
    {
        return $this->header()->messageType;
    }
}
