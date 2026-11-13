<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;

/**
 * Converts DuckDB's BIGNUM storage to its exact decimal string.
 *
 * The storage is a three-byte header followed by big-endian magnitude bytes.
 * Negative values store the bitwise complement of the magnitude.
 */
final class Bignum
{
    public static function fromStorage(string $raw): string
    {
        if (strlen($raw) < 4) {
            throw new ProtocolException('BIGNUM value is too short');
        }
        $negative = (ord($raw[0]) & 0x80) === 0;
        $magnitude = substr($raw, 3);
        if ($negative) {
            $magnitude = ~$magnitude;
        }
        $value = BigInt::fromBytesLE(strrev($magnitude), false);

        return (string) ($negative ? $value->neg() : $value);
    }
}
