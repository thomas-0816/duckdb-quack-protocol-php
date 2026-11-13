<?php

declare(strict_types=1);

namespace Quack\Protocol\Messages;

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
