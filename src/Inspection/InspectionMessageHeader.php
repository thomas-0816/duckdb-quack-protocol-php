<?php

namespace DuckDB\Quack\Inspection;

use DuckDB\Quack\Messages\MessageType;

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
