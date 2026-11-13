<?php

namespace Quack\Protocol\Types;

use Quack\Protocol\Exceptions\ProtocolException;

/**
 * The two 64-bit halves of a 128-bit integer, matching DuckDB's hugeint layout.
 */
final class HugeIntParts
{
    public function __construct(
        public readonly int $upper,
        public readonly int|string $lower,
    ) {
    }

    public function toSignedBigInt(): BigInt
    {
        $lowerBytes = BigInt::coerce($this->lower)->toBytesLE(8, false);
        $upperBytes = BigInt::fromInt($this->upper)->toBytesLE(8, true);
        return BigInt::fromBytesLE($lowerBytes . $upperBytes, true);
    }

    public function toUnsignedBigInt(): BigInt
    {
        $lowerBytes = BigInt::coerce($this->lower)->toBytesLE(8, false);
        $upperBytes = BigInt::fromInt($this->upper)->toBytesLE(8, true);
        return BigInt::fromBytesLE($lowerBytes . $upperBytes, false);
    }

    public static function fromSignedBigInt(BigInt $value): self
    {
        $bytes = $value->toBytesLE(16, true);
        return new self(
            BigInt::fromBytesLE(substr($bytes, 8, 8), true)->toInt(),
            BigInt::fromBytesLE(substr($bytes, 0, 8), false)->toIntOrString(),
        );
    }

    public static function fromUnsignedBigInt(BigInt $value): self
    {
        $bytes = $value->toBytesLE(16, false);
        return new self(
            BigInt::fromBytesLE(substr($bytes, 8, 8), true)->toInt(),
            BigInt::fromBytesLE(substr($bytes, 0, 8), false)->toIntOrString(),
        );
    }

    /**
     * Parses a canonical UUID string into DuckDB's HUGEINT representation.
     */
    public static function fromUuid(string $uuid): self
    {
        $hex = strtolower(str_replace('-', '', $uuid));
        if (strlen($hex) !== 32 || preg_match('/^[0-9a-f]{32}$/', $hex) !== 1) {
            throw new ProtocolException("invalid UUID string {$uuid}");
        }
        $upperBytes = strrev((string) hex2bin(substr($hex, 0, 16)));
        $upperBytes[7] = chr(ord($upperBytes[7]) ^ 0x80);
        $lowerBytes = strrev((string) hex2bin(substr($hex, 16, 16)));
        return new self(
            BigInt::fromBytesLE($upperBytes, true)->toInt(),
            BigInt::fromBytesLE($lowerBytes, false)->toIntOrString(),
        );
    }

    /**
     * Formats DuckDB's HUGEINT UUID representation as a canonical UUID string.
     *
     * DuckDB flips the most significant bit when storing UUIDs, so it is
     * flipped back here.
     */
    public function uuid(): string
    {
        $upperBytes = BigInt::fromInt($this->upper)->toBytesLE(8, true);
        $upperBytes[7] = chr(ord($upperBytes[7]) ^ 0x80);
        $lowerBytes = BigInt::coerce($this->lower)->toBytesLE(8, false);
        $upperHex = bin2hex(strrev($upperBytes));
        $lowerHex = bin2hex(strrev($lowerBytes));
        return substr($upperHex, 0, 8) . '-'
            . substr($upperHex, 8, 4) . '-'
            . substr($upperHex, 12, 4) . '-'
            . substr($lowerHex, 0, 4) . '-'
            . substr($lowerHex, 4, 12);
    }

    public function __toString(): string
    {
        return $this->uuid();
    }
}
