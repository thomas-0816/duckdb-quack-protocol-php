<?php

declare(strict_types=1);

namespace Quack\Protocol\Transport;

use Quack\Protocol\Connection\Constants;
use Quack\Protocol\Error\TransportErrorKind;
use Quack\Protocol\Exceptions\HttpStatusException;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Exceptions\ServerException;
use Quack\Protocol\Exceptions\TransportException;
use Quack\Protocol\Messages\ErrorMessage;
use Quack\Protocol\Messages\MessageCodec;
use Quack\Protocol\Messages\QuackMessage;
use Quack\Protocol\QuackVersion;

/**
 * HTTP(S) transport for one Quack endpoint, backed by ext-curl.
 */
final class Transport
{
    private function __construct(
        private readonly string $baseUrl,
        private readonly bool $ssl,
        private float $timeout,
        private readonly float $connectTimeout,
        private readonly ?string $sslCertificate,
    ) {
    }

    public static function new(
        string $uri,
        float $connectTimeout,
        float $timeout,
        ?string $sslCertificate = null,
    ): self {
        $parsed = UriParser::parseQuackUri($uri);

        if ($sslCertificate !== null && !$parsed->ssl) {
            throw new ProtocolException(
                'ssl_certificate requires an https:// URL',
            );
        }

        if ($timeout <= 0) {
            throw new ProtocolException('Quack request timeout must be greater than zero');
        }
        if ($connectTimeout <= 0) {
            throw new ProtocolException('Quack connect timeout must be greater than zero');
        }

        return new self(
            rtrim($parsed->baseUrl, '/'),
            $parsed->ssl,
            $timeout,
            $connectTimeout,
            $sslCertificate,
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    /**
     * The heartbeat lease matching the request timeout, so a request cannot
     * outlive it.
     */
    public function heartbeatTimeout(): int
    {
        return (int) ceil($this->timeout);
    }

    public function withTimeout(float $timeout): self
    {
        $clone = clone $this;
        $clone->timeout = $timeout;
        return $clone;
    }

    public function send(QuackMessage $message, QuackVersion $version): QuackMessage
    {
        $bytes = MessageCodec::encodeMessageForVersion($message, $version);
        $curl = curl_init($this->baseUrl . Constants::QUACK_ENDPOINT);
        if ($curl === false) {
            throw new TransportException(
                TransportErrorKind::Other,
                'failed to initialise curl',
            );
        }

        $headers = [
            'Accept: ' . Constants::DUCKDB_MIME_TYPE,
            'Content-Type: ' . Constants::DUCKDB_MIME_TYPE,
        ];

        $timeoutMs = (int) max(1, round($this->timeout * 1000));
        $connectTimeoutMs = (int) max(1, round(
            min($this->connectTimeout, $this->timeout) * 1000,
        ));

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bytes,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $connectTimeoutMs,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_BUFFERSIZE => 8192,
        ];
        if ($this->ssl) {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            if ($this->sslCertificate !== null) {
                $options[CURLOPT_CAINFO_BLOB] = $this->sslCertificate;
            }
        }
        self::applyCurlOptions($curl, $options);

        $body = curl_exec($curl);
        if ($body === false) {
            $errno = curl_errno($curl);
            $error = curl_error($curl);
            throw self::transportError($errno, $error);
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($status < 200 || $status >= 300) {
            $raw = (string) $body;
            if (strlen($raw) > Constants::MAX_HTTP_STATUS_BODY_BYTES) {
                $raw = '';
            }
            throw new HttpStatusException($status, $raw);
        }

        $decoded = MessageCodec::decodeMessageForVersion((string) $body, $version);
        if ($decoded instanceof ErrorMessage) {
            throw new ServerException($decoded->error);
        }
        return $decoded;
    }

    /**
     * Applies curl options one at a time so option value types stay unchecked
     * by static analysis; curl_setopt_array's stubs are narrower than curl's
     * actual accepted values.
     *
     * @param array<int,mixed> $options
     */
    private static function applyCurlOptions(\CurlHandle $curl, array $options): void
    {
        foreach ($options as $option => $value) {
            if (!curl_setopt($curl, $option, $value)) {
                throw new TransportException(
                    TransportErrorKind::Other,
                    "failed to set curl option {$option}",
                );
            }
        }
    }

    private static function transportError(int $errno, string $message): TransportException
    {
        $kind = match (true) {
            $errno === CURLE_OPERATION_TIMEDOUT => TransportErrorKind::Timeout,
            in_array($errno, [
                CURLE_COULDNT_RESOLVE_PROXY,
                CURLE_COULDNT_RESOLVE_HOST,
                CURLE_COULDNT_CONNECT,
                CURLE_SSL_CONNECT_ERROR,
                CURLE_SSL_CERTPROBLEM,
                CURLE_SSL_CIPHER,
                CURLE_SSL_CACERT,
                CURLE_SSL_CACERT_BADFILE,
                // CURLE_SSL_ISSUER_ERROR (83) and CURLE_SSL_INVALIDCERTSTATUS
                // (91) are missing from some curl/PHPStan constant stubs.
                83,
                CURLE_SSL_PINNEDPUBKEYNOTMATCH,
                91,
            ], true) => TransportErrorKind::Connect,
            default => TransportErrorKind::Other,
        };
        return new TransportException(
            $kind,
            $message === '' ? "curl error {$errno}" : "curl error {$errno}: {$message}",
        );
    }
}
