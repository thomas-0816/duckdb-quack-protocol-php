<?php

namespace Quack\Protocol\Inspection;

use Quack\Protocol\Error\ServerError;
use Quack\Protocol\QuackVersion;

/**
 * A bounded control response decoded by the inspection helpers.
 */
final class ControlResponse
{
    private function __construct(
        public readonly ControlResponseKind $kind,
        public readonly ?string $connectionId = null,
        public readonly ?QuackVersion $quackVersion = null,
        public readonly ?int $heartbeatTimeout = null,
        public readonly ?ServerError $error = null,
    ) {
    }

    public static function connected(
        string $connectionId,
        ?QuackVersion $quackVersion,
        ?int $heartbeatTimeout,
    ): self {
        return new self(
            ControlResponseKind::Connected,
            $connectionId,
            $quackVersion,
            $heartbeatTimeout,
        );
    }

    public static function success(): self
    {
        return new self(ControlResponseKind::Success);
    }

    public static function error(ServerError $error): self
    {
        return new self(ControlResponseKind::Error, error: $error);
    }
}
