<?php

namespace DuckDB\Quack\Transport;

use DuckDB\Quack\Exceptions\HttpStatusException;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\Exceptions\TransportException;
use DuckDB\Quack\Messages\ErrorMessage;
use DuckDB\Quack\Messages\MessageCodec;
use DuckDB\Quack\Messages\QuackMessage;
use SensitiveParameter;

/**
 * HTTP(S) transport for one Quack endpoint, backed by PHP streams.
 *
 * It needs no extension beyond PHP's stream layer. Every request uses a fresh
 * connection, and TLS 1.2 or later is enforced for https:// endpoints.
 */
final class TransportPhp implements TransportInterface
{
    private ?string $caFile = null;

    private bool $debug = false;

    private function __construct(
        private readonly ParsedQuackUri $parsedUri,
        #[SensitiveParameter]
        private readonly string $authToken,
        private int $timeout,
        private int $connectTimeout,
        private readonly ?string $sslCertificate,
    ) {
    }

    public static function new(
        string $uri,
        string $authToken,
        int $connectTimeout,
        int $timeout,
        ?string $sslCertificate = null,
    ): static {
        $parsedUri = UriParser::parseQuackUri($uri);

        if ($sslCertificate !== null && !$parsedUri->ssl) {
            throw new ProtocolException('ssl_certificate requires an https:// URL');
        }

        if ($timeout <= 0) {
            throw new ProtocolException('Quack request timeout must be greater than zero');
        }
        if ($connectTimeout <= 0) {
            throw new ProtocolException('Quack connect timeout must be greater than zero');
        }

        return new static(
            $parsedUri,
            $authToken,
            $timeout,
            $connectTimeout,
            $sslCertificate,
        );
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function setConnectTimeout(int $connectTimeout): static
    {
        $this->connectTimeout = $connectTimeout;
        return $this;
    }

    public function setTimeout(int $timeout): static
    {
        $this->timeout = $timeout;
        return $this;
    }

    public function setDebug(bool $debug): static
    {
        $this->debug = $debug;
        return $this;
    }

    public function __clone()
    {
        // A cloned transport must not share the temporary CA file.
        $this->caFile = null;
    }

    public function __destruct()
    {
        if ($this->caFile !== null) {
            @unlink($this->caFile);
            $this->caFile = null;
        }
    }

    public function send(QuackMessage $message, int $version): QuackMessage
    {
        $payload = MessageCodec::encodeMessageForVersion($message, $version);

        $status = 0;
        $body = '';
        $host = $this->parsedUri->host;
        $host = (str_contains($host, ':') ? '[' . $host . ']' : $host) . ':' . $this->parsedUri->port;
        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'SNI_enabled' => true,
            'peer_name' => $this->parsedUri->host,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ];
        if ($this->sslCertificate !== null) {
            $ssl['cafile'] = $this->caFile();
        }
        $context = stream_context_create(['ssl' => $ssl]);
        $remote = ($this->parsedUri->ssl ? 'tls' : 'tcp') . '://' . $host;
        $timeout = (float) min($this->connectTimeout, $this->timeout);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            $detail = $errstr !== '' ? "{$errstr} (errno {$errno})" : "errno {$errno}";
            throw new TransportException("failed to connect to {$remote}: {$detail}");
        }
        stream_set_timeout($stream, $this->timeout);

        try {
            $head = 'POST /quack HTTP/1.1' . "\r\n"
                . 'Host: ' . $host . "\r\n"
                . 'Accept: application/vnd.duckdb' . "\r\n"
                . 'Content-Type: application/vnd.duckdb' . "\r\n"
                . 'Content-Length: ' . strlen($payload) . "\r\n"
                . 'Connection: close' . "\r\n"
                . "\r\n";
            if (fwrite($stream, $head) !== strlen($head) || fwrite($stream, $payload) !== strlen($payload)) {
                $meta = stream_get_meta_data($stream);
                throw new TransportException($meta['timed_out'] ? 'timed out while writing request' : 'failed to write request');
            }
            [$status, $body] = $this->readResponse($stream);
        } finally {
            @fclose($stream);
        }

        if ($this->debug) {
            echo substr((isset($message->sql) && is_string($message->sql)) ? $message->sql : $message::class, 0, 256), PHP_EOL;
            echo json_encode([
                'http_code' => $status,
                'size_upload' => strlen($payload),
                'size_download' => strlen($body),
            ]), PHP_EOL, PHP_EOL;
        }

        if ($status < 200 || $status >= 300) {
            $raw = strlen($body) > 16 * 1024 ? substr($body, 0, 16 * 1024) : $body;
            throw new HttpStatusException($status, $raw);
        }

        $decoded = MessageCodec::decodeMessageForVersion($body, $version);
        if ($decoded instanceof ErrorMessage) {
            throw new ServerException($decoded->error);
        }
        return $decoded;
    }

    /**
     * @param resource $stream
     * @return array{0:int,1:string}
     */
    private function readResponse($stream): array
    {
        $raw = stream_get_line($stream, 8192, "\r\n\r\n");
        if ($raw === false) {
            $meta = stream_get_meta_data($stream);
            throw new TransportException($meta['timed_out'] ? 'timed out while reading response headers' : 'connection closed while reading response headers');
        }
        if ($raw === '') {
            throw new TransportException('empty response headers');
        }
        if (strlen($raw) === 8192) {
            throw new TransportException('response headers exceeded 8 KiB');
        }

        if (preg_match('#^HTTP/\d+(?:\.\d+)?\s+(\d{3})(?:\s|$)#', $raw, $matches) !== 1) {
            throw new TransportException('malformed HTTP status line');
        }

        $length = preg_match('/^Content-Length:\s*(\d+)\s*$/mi', $raw, $lengthMatches) === 1 ? (int) $lengthMatches[1] : null;
        $body = stream_get_contents($stream, $length);
        if ($body === false || ($length !== null && strlen($body) !== $length)) {
            $meta = stream_get_meta_data($stream);
            throw new TransportException($meta['timed_out'] ? 'timed out while reading response body' : 'connection closed while reading response body');
        }

        return [(int) $matches[1], $body];
    }

    private function caFile(): string
    {
        if ($this->caFile !== null) {
            return $this->caFile;
        }

        $certificate = $this->sslCertificate;
        if ($certificate === null) {
            throw new TransportException('no CA certificate configured');
        }

        $path = tempnam(sys_get_temp_dir(), 'quack-ca-');
        if ($path === false) {
            throw new TransportException('failed to create temporary CA file');
        }
        if (@file_put_contents($path, $certificate) === false) {
            @unlink($path);
            throw new TransportException('failed to write temporary CA file');
        }
        @chmod($path, 0o600);
        return $this->caFile = $path;
    }
}
