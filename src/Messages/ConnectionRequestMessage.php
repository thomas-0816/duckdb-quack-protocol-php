<?php

namespace DuckDB\Quack\Messages;

final class ConnectionRequestMessage extends QuackMessage
{
    public function __construct(
        public readonly MessageHeader $header,
        public readonly ?string $authString = null,
        public readonly ?string $clientDuckdbVersion = null,
        public readonly ?string $clientPlatform = null,
        public readonly int|string $minSupportedQuackVersion = 0,
        public readonly int|string $maxSupportedQuackVersion = 0,
        public readonly ?string $clientId = null,
        public readonly int|string $heartbeatTimeoutSeconds = 0,
    ) {
    }

    public function header(): MessageHeader
    {
        return $this->header;
    }
}
