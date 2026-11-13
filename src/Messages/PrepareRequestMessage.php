<?php

declare(strict_types=1);

namespace Quack\Protocol\Messages;

use Quack\Protocol\Types\HugeIntParts;

final class PrepareRequestMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly string $sql,
        public readonly ?HugeIntParts $queryUuid = null,
        public readonly int|string|null $inlineRows = null,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
