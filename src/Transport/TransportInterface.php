<?php

namespace DuckDB\Quack\Transport;

use DuckDB\Quack\Messages\QuackMessage;

/**
 * HTTP(S) transport for one Quack endpoint.
 */
interface TransportInterface
{
    public static function new(
        string $uri,
        string $authToken,
        int $connectTimeout,
        int $timeout,
        ?string $sslCertificate = null,
    ): static;

    public function getAuthToken(): string;

    public function setConnectTimeout(int $connectTimeout): static;

    public function setTimeout(int $timeout): static;

    public function setDebug(bool $debug): static;

    public function send(QuackMessage $message, int $version): QuackMessage;
}
