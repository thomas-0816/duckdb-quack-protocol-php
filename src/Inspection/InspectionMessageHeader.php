<?php

declare(strict_types=1);

namespace Quack\Protocol\Inspection;

use Quack\Protocol\Messages\MessageType;

/**
 * A Quack message header inspected without decoding its body.
 */
final class InspectionMessageHeader
{
    public function __construct(
        public readonly MessageType $operation,
        public readonly ?string $connectionId,
        public readonly int|string|null $clientQueryId,
        /** Bytes occupied by the header; excludes the opaque body. */
        public readonly int $encodedLen,
    ) {
    }
}
