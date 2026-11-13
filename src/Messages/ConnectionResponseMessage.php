<?php

namespace DuckDB\Quack\Messages;

final class ConnectionResponseMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly ?string $serverDuckdbVersion = null,
        public readonly ?string $serverPlatform = null,
        public readonly int|string|null $quackVersion = null,
        public readonly int|string|null $heartbeatTimeoutSeconds = null,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
