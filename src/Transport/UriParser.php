<?php

namespace DuckDB\Quack\Transport;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\UrlException;

/**
 * Parses the listen URL advertised by `quack_serve`.
 *
 * The URL must be http:// or https://; the scheme selects the transport
 * security. A missing port defaults to 80 for http and 443 for https.
 */
final class UriParser
{
    public static function parseQuackUri(string $input): ParsedQuackUri
    {
        $uri = trim($input);
        if ($uri === '') {
            throw new ProtocolException('Quack URI is empty');
        }

        if (!str_starts_with($uri, 'http://') && !str_starts_with($uri, 'https://')) {
            throw new ProtocolException(
                "invalid Quack URI {$input}: expected an http:// or https:// URL",
            );
        }

        $parts = parse_url($uri);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new UrlException("invalid Quack URI {$input}");
        }
        $scheme = strtolower((string) $parts['scheme']);
        $ssl = $scheme === 'https';
        $port = isset($parts['port'])
            ? self::parsePort((string) $parts['port'])
            : ($ssl ? 443 : 80);
        $host = self::stripBrackets((string) $parts['host']);
        if ($host === ''
            || ($host === $parts['host'] && str_contains($host, ':'))) {
            throw new ProtocolException("invalid Quack URI host {$input}");
        }

        return new ParsedQuackUri(
            baseUrl: $scheme . '://' . self::hostForBaseUrl($host) . ':' . $port,
            host: $host,
            port: $port,
            ssl: $ssl,
        );
    }

    private static function parsePort(string $value): int
    {
        if ($value === '' || preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new ProtocolException("invalid Quack URI port {$value}");
        }
        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new ProtocolException("invalid Quack URI port {$value}");
        }
        return $port;
    }

    private static function stripBrackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }
        return $host;
    }

    private static function hostForBaseUrl(string $host): string
    {
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            return '[' . $host . ']';
        }
        return $host;
    }
}
