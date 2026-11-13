<?php

namespace DuckDB\Quack;

/**
 * What the server reported when a session was opened.
 */
final class QuackConnectionInfo
{
    public function __construct(
        public readonly ?string $serverDuckdbVersion = null,
        public readonly ?string $serverPlatform = null,
        public readonly ?QuackVersion $quackVersion = null,
    ) {
    }
}
