<?php

declare(strict_types=1);

namespace Quack\Protocol\Transport;

use Quack\Protocol\Exceptions\ProtocolException;

/**
 * SHA-256 fingerprint of the one server certificate a client trusts.
 *
 * The spelling follows DuckDB's `ssl_fingerprint`: hex digits in any case,
 * optionally separated by colons or spaces, with an optional `sha256:` prefix.
 */
final class CertificatePin
{
    private const SHA256_LEN = 32;

    private function __construct(private readonly string $bytes)
    {
    }

    public static function parse(string $fingerprint): self
    {
        $trimmed = trim($fingerprint);
        $digits = $trimmed;
        if (strncasecmp($digits, 'sha256:', 7) === 0) {
            $digits = substr($digits, 7);
        }
        $digits = str_replace([':', ' '], '', $digits);
        if (strlen($digits) !== self::SHA256_LEN * 2
            || preg_match('/^[0-9a-fA-F]+$/', $digits) !== 1) {
            throw new ProtocolException(
                "invalid ssl_fingerprint \"{$fingerprint}\": expected the 64 hex digits "
                . "of the server certificate's SHA-256 fingerprint, optionally separated by colons",
            );
        }
        $bytes = hex2bin($digits);
        if ($bytes === false || strlen($bytes) !== self::SHA256_LEN) {
            throw new ProtocolException(
                "invalid ssl_fingerprint \"{$fingerprint}\": expected 64 hex digits",
            );
        }
        return new self($bytes);
    }

    public function bytes(): string
    {
        return $this->bytes;
    }

    public function hex(): string
    {
        return strtoupper(bin2hex($this->bytes));
    }

    public function matchesDer(string $certificateDer): bool
    {
        return hash_equals($this->bytes, hash('sha256', $certificateDer, true));
    }

    public function matchesPem(string $certificatePem): bool
    {
        $der = self::pemToDer($certificatePem);
        return $der !== null && $this->matchesDer($der);
    }

    /**
     * Converts a PEM certificate to DER without re-encoding it through OpenSSL.
     */
    public static function pemToDer(string $pem): ?string
    {
        if (preg_match(
            '/-----BEGIN CERTIFICATE-----\s*(.+?)\s*-----END CERTIFICATE-----/s',
            $pem,
            $matches,
        ) !== 1) {
            return null;
        }
        $body = preg_replace('/\s+/', '', $matches[1]);
        if ($body === null || $body === '') {
            return null;
        }
        $der = base64_decode($body, true);
        return $der === false ? null : $der;
    }

    /**
     * libcurl's `CURLOPT_PINNEDPUBLICKEY` value for a certificate's
     * SubjectPublicKeyInfo, or null when the certificate cannot be parsed.
     */
    public static function publicKeyPin(string $certificateDer): ?string
    {
        $spki = DerReader::subjectPublicKeyInfo($certificateDer);
        if ($spki === null) {
            return null;
        }
        return 'sha256//' . base64_encode(hash('sha256', $spki, true));
    }
}
