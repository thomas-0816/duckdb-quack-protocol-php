<?php

namespace DuckDB\Quack\Transport;

use CurlHandle;
use DuckDB\Quack\Error\TransportErrorKind;
use DuckDB\Quack\Exceptions\HttpStatusException;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\Exceptions\TransportException;
use DuckDB\Quack\Messages\ErrorMessage;
use DuckDB\Quack\Messages\MessageCodec;
use DuckDB\Quack\Messages\QuackMessage;

/**
 * HTTP(S) transport for one Quack endpoint, backed by ext-curl.
 */
final class Transport
{
    private ?CurlHandle $curl = null;

    private function __construct(
        private readonly string $baseUrl,
        private readonly bool $ssl,
        private readonly string $authToken,
        private int $timeout,
        private readonly int $connectTimeout,
        private readonly ?string $sslCertificate,
    ) {
    }

    public static function new(
        string $uri,
        string $authToken,
        int $connectTimeout,
        int $timeout,
        ?string $sslCertificate = null,
    ): self {
        $parsed = UriParser::parseQuackUri($uri);

        if ($sslCertificate !== null && !$parsed->ssl) {
            throw new ProtocolException('ssl_certificate requires an https:// URL');
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
            $authToken,
            $timeout,
            $connectTimeout,
            $sslCertificate,
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function authToken(): string
    {
        return $this->authToken;
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function withTimeout(int $timeout): self
    {
        $clone = clone $this;
        $clone->timeout = $timeout;
        return $clone;
    }

    public function __clone()
    {
        // A cloned transport must not share the parent's connection state.
        $this->curl = null;
    }

    /**
     * Returns the reusable curl handle, creating and configuring it on first
     * use. Reusing the handle keeps the HTTP connection alive between
     * requests, avoiding a TCP/TLS handshake per prepare and fetch call.
     */
    private function curl(): \CurlHandle
    {
        if ($this->curl !== null) {
            return $this->curl;
        }

        $curl = curl_init($this->baseUrl . '/quack');
        if ($curl === false) {
            throw new TransportException(TransportErrorKind::Other, 'failed to initialise curl');
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.duckdb', 'Content-Type: application/vnd.duckdb', 'Expect:'],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_TCP_NODELAY => true,
            CURLOPT_TCP_KEEPALIVE => true,
        ];
        if ($this->ssl) {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            if ($this->sslCertificate !== null) {
                $options[CURLOPT_CAINFO_BLOB] = $this->sslCertificate;
            }
        }
        if (!curl_setopt_array($curl, $options)) {
            throw new TransportException(TransportErrorKind::Other, 'failed to set curl options');
        }

        return $this->curl = $curl;
    }

    public function send(QuackMessage $message, int $version): QuackMessage
    {
        $curl = $this->curl();

        $options = [
            CURLOPT_POSTFIELDS => MessageCodec::encodeMessageForVersion($message, $version),
            CURLOPT_TIMEOUT_MS => (int) max(1, round($this->timeout * 1000)),
            CURLOPT_CONNECTTIMEOUT_MS => (int) max(1, round(min($this->connectTimeout, $this->timeout) * 1000)),
        ];
        if (!curl_setopt_array($curl, $options)) {
            throw new TransportException(TransportErrorKind::Other, 'failed to set curl options');
        }

        $body = curl_exec($curl);
        if ($body === false) {
            $errno = curl_errno($curl);
            $message = curl_error($curl);
            // Do not reuse a handle whose transfer failed; the next call
            // starts with a fresh connection.
            $this->curl = null;
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
            throw new TransportException($kind, $message === '' ? "curl error {$errno}" : "curl error {$errno}: {$message}");
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if ($status < 200 || $status >= 300) {
            $raw = (string) $body;
            if (strlen($raw) > 16 * 1024) {
                $raw = substr($raw, 0, 16 * 1024);
            }
            throw new HttpStatusException($status, $raw);
        }

        $decoded = MessageCodec::decodeMessageForVersion((string) $body, $version);
        if ($decoded instanceof ErrorMessage) {
            throw new ServerException($decoded->error);
        }
        return $decoded;
    }
}
