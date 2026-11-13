<?php

declare(strict_types=1);

namespace Quack\Protocol\Transport;

use Quack\Protocol\Connection\Constants;
use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Exceptions\UrlException;

/**
 * Parses the URI forms DuckDB's Quack client accepts:
 *
 *   quack://host:port, quack:host:port, host:port, and http(s)://host:port
 */
final class UriParser
{
    public static function parseQuackUri(string $input, ?bool $sslOverride = null): ParsedQuackUri
    {
        $uri = trim($input);
        if ($uri === '') {
            throw new ProtocolException('Quack URI is empty');
        }

        if (str_starts_with($uri, 'http://') || str_starts_with($uri, 'https://')) {
            $parts = parse_url($uri);
            if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
                throw new UrlException("invalid Quack URI {$input}");
            }
            $scheme = strtolower((string) $parts['scheme']);
            $ssl = $scheme === 'https';
            $port = isset($parts['port'])
                ? (int) $parts['port']
                : ($ssl ? 443 : 80);
            $host = self::stripBrackets((string) $parts['host']);
            if ($host === '') {
                throw new ProtocolException("invalid Quack URI host {$input}");
            }
            return new ParsedQuackUri(
                baseUrl: $scheme . '://' . self::hostForBaseUrl($host) . ':' . $port,
                host: $host,
                port: $port,
                ssl: $ssl,
            );
        }

        $rest = $uri;
        if (str_starts_with($rest, 'quack://')) {
            $rest = substr($rest, strlen('quack://'));
        } elseif (str_starts_with($rest, 'quack:')) {
            $rest = substr($rest, strlen('quack:'));
        }
        if ($rest === '') {
            throw new ProtocolException("invalid Quack URI {$input}");
        }

        [$host, $port] = self::parseHostPort($rest);
        $ssl = $sslOverride ?? false;
        $protocol = $ssl ? 'https' : 'http';

        return new ParsedQuackUri(
            baseUrl: $protocol . '://' . self::hostForBaseUrl($host) . ':' . $port,
            host: $host,
            port: $port,
            ssl: $ssl,
        );
    }

    /**
     * @return array{0:string,1:int}
     */
    private static function parseHostPort(string $value): array
    {
        if (str_starts_with($value, '[')) {
            $end = strpos($value, ']');
            if ($end === false) {
                throw new ProtocolException("invalid IPv6 Quack URI host {$value}");
            }
            $host = substr($value, 1, $end - 1);
            $suffix = substr($value, $end + 1);
            $port = str_starts_with($suffix, ':')
                ? self::parsePort(substr($suffix, 1))
                : Constants::DEFAULT_QUACK_PORT;
            return [$host, $port];
        }

        $colonCount = substr_count($value, ':');
        if ($colonCount === 0) {
            return [$value, Constants::DEFAULT_QUACK_PORT];
        }
        if ($colonCount === 1) {
            [$host, $port] = explode(':', $value, 2);
            if ($host === '') {
                throw new ProtocolException("invalid Quack URI host {$value}");
            }
            return [$host, self::parsePort($port)];
        }

        throw new ProtocolException(
            "IPv6 Quack URI hosts must be enclosed in []: {$value}",
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
