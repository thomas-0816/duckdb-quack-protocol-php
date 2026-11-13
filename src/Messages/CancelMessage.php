<?php

namespace Quack\Protocol\Messages;

use Quack\Protocol\Types\HugeIntParts;

final class CancelMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly HugeIntParts $queryUuid,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
