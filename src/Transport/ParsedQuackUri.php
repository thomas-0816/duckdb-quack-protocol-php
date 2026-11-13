<?php

namespace Quack\Protocol\Transport;

/**
 * The parts of a Quack URI that the HTTP transport needs.
 */
final class ParsedQuackUri
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $host,
        public readonly int $port,
        public readonly bool $ssl,
    ) {
    }
}
