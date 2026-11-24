<?php

namespace DuckDB\Quack\Transport;

use CurlHandle;
use DuckDB\Quack\Exceptions\HttpStatusException;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\Exceptions\TransportException;
use DuckDB\Quack\Messages\ErrorMessage;
use DuckDB\Quack\Messages\MessageCodec;
use DuckDB\Quack\Messages\QuackMessage;
use SensitiveParameter;

/**
 * HTTP(S) transport for one Quack endpoint, backed by ext-curl.
 */
final class TransportCurl implements TransportInterface
{
    private ?CurlHandle $curl = null;

    private bool $debug = false;

    private function __construct(
        private readonly ParsedQuackUri $parsedUri,
        #[SensitiveParameter]
        private readonly string $authToken,
        private int $timeout,
        private int $connectTimeout,
        private readonly string $sslCertificateFile,
    ) {
    }

    public static function new(
        string $uri,
        string $authToken,
        int $connectTimeout,
        int $timeout,
        string $sslCertificateFile,
    ): static {
        $parsedUri = UriParser::parseQuackUri($uri);

        if ($sslCertificateFile !== '' && !$parsedUri->ssl) {
            throw new ProtocolException('ssl_certificate requires https in the base URL');
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
            $sslCertificateFile,
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
        // A cloned transport must not share the parent's connection state.
        $this->curl = null;
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
            throw new TransportException('failed to set curl options');
        }

        $body = curl_exec($curl);
        if ($this->debug) {
            echo substr((isset($message->sql) && is_string($message->sql)) ? $message->sql : $message::class, 0, 256), PHP_EOL;
            $info = curl_getinfo($curl);
            echo json_encode([
                'http_code' => $info['http_code'],
                'size_upload' => $info['size_upload'],
                'size_download' => $info['size_download'],
                'appconnect_time_us' => $info['appconnect_time_us'],
                'connect_time_us' => $info['connect_time_us'],
                'namelookup_time_us' => $info['namelookup_time_us'],
                'pretransfer_time_us' => $info['pretransfer_time_us'],
                'starttransfer_time_us' => $info['starttransfer_time_us'],
                'total_time_us' => $info['total_time_us'],
            ]), PHP_EOL, PHP_EOL;
        }
        if (!is_string($body)) {
            $errno = curl_errno($curl);
            $message = curl_error($curl);
            // Do not reuse a handle whose transfer failed; the next call
            // starts with a fresh connection.
            $this->curl = null;
            throw new TransportException($message === '' ? "curl error {$errno}" : "curl error {$errno}: {$message}");
        }

        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

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
     * Returns the reusable curl handle, creating and configuring it on first
     * use. Reusing the handle keeps the HTTP connection alive between
     * requests, avoiding a TCP/TLS handshake per prepare and fetch call.
     */
    private function curl(): CurlHandle
    {
        if ($this->curl !== null) {
            return $this->curl;
        }

        $curl = curl_init(rtrim($this->parsedUri->baseUrl, '/') . '/quack');
        if ($curl === false) {
            throw new TransportException('failed to initialise curl');
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
            CURLOPT_TCP_KEEPALIVE => 1,
            // CURLOPT_BUFFERSIZE => keep default 16kB
            // CURLOPT_UPLOAD_BUFFERSIZE => keep default 64kB
        ];
        if ($this->parsedUri->ssl) {
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            $options[CURLOPT_SSLVERSION] = CURL_SSLVERSION_TLSv1_2; // TLSv1.2 or later
            if ($this->sslCertificateFile !== '') {
                $options[CURLOPT_CAINFO] = $this->sslCertificateFile;
            }
        }
        if (!curl_setopt_array($curl, $options)) {
            throw new TransportException('failed to set curl options');
        }

        return $this->curl = $curl;
    }
}
