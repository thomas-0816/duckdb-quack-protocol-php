<?php

declare(strict_types=1);

namespace Quack\Protocol\Transport;

/**
 * A minimal DER reader for the one X.509 field the TLS pin check needs.
 *
 * It deliberately does not validate certificates: a pinned connection trusts
 * exactly the certificate whose SHA-256 fingerprint matches, and only needs the
 * certificate's SubjectPublicKeyInfo to configure libcurl's public-key pin.
 */
final class DerReader
{
    /**
     * The DER-encoded SubjectPublicKeyInfo of a single, complete X.509
     * certificate, or null when the input is not exactly one well-formed
     * certificate.
     */
    public static function subjectPublicKeyInfo(string $certificate): ?string
    {
        $outer = self::readElement($certificate, 0);
        if ($outer === null || $outer['tag'] !== 0x30 || $outer['end'] !== strlen($certificate)) {
            return null;
        }

        $outerChildren = self::children($outer['value']);
        if ($outerChildren === null || count($outerChildren) < 3) {
            return null;
        }
        $tbs = $outerChildren[0];
        if ($tbs['tag'] !== 0x30) {
            return null;
        }

        $children = self::children($tbs['value']);
        if ($children === null) {
            return null;
        }
        if ($children === []) {
            return null;
        }

        $index = $children[0]['tag'] === 0xa0 ? 6 : 5;
        $spki = $children[$index] ?? null;
        if ($spki === null || $spki['tag'] !== 0x30) {
            return null;
        }
        return $spki['raw'];
    }

    /**
     * @return array{tag:int,value:string,raw:string,end:int}|null
     */
    private static function readElement(string $bytes, int $offset): ?array
    {
        $length = strlen($bytes);
        if ($offset + 2 > $length) {
            return null;
        }
        $start = $offset;
        $tag = ord($bytes[$offset++]);
        // X.509 uses only single-byte tags; reject multi-byte tags.
        if (($tag & 0x1f) === 0x1f) {
            return null;
        }
        $first = ord($bytes[$offset++]);

        if ($first < 0x80) {
            $contentLength = $first;
        } else {
            $octets = $first & 0x7f;
            if ($octets === 0 || $octets > 4 || $offset + $octets > $length) {
                return null;
            }
            $contentLength = 0;
            for ($index = 0; $index < $octets; $index++) {
                $contentLength = ($contentLength << 8) | ord($bytes[$offset++]);
            }
            if ($contentLength < 0x80) {
                // Non-minimal length encoding.
                return null;
            }
        }

        if ($contentLength > $length - $offset) {
            return null;
        }
        $value = substr($bytes, $offset, $contentLength);
        $raw = substr($bytes, $start, $offset + $contentLength - $start);

        return [
            'tag' => $tag,
            'value' => $value,
            'raw' => $raw,
            'end' => $offset + $contentLength,
        ];
    }

    /**
     * @return list<array{tag:int,value:string,raw:string,end:int}>|null
     */
    private static function children(string $value): ?array
    {
        $children = [];
        $offset = 0;
        $length = strlen($value);
        while ($offset < $length) {
            $element = self::readElement($value, $offset);
            if ($element === null) {
                return null;
            }
            $children[] = $element;
            $offset = $element['end'];
        }
        return $children;
    }
}
