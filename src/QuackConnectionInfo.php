<?php

declare(strict_types=1);

namespace Quack\Protocol;

/**
 * What the server reported when a session was opened.
 */
final class QuackConnectionInfo
{
    public function __construct(
        public readonly ?string $serverDuckdbVersion = null,
        public readonly ?string $serverPlatform = null,
        public readonly ?QuackVersion $quackVersion = null,
        public readonly ?int $heartbeatTimeout = null,
    ) {
    }
}
