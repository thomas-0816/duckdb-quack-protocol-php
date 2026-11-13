<?php

declare(strict_types=1);

namespace Quack\Protocol\Messages;

final class DisconnectMessage extends QuackMessage
{
    public function __construct(public readonly MessageHeader $header)
    {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
