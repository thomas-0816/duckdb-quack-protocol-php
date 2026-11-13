<?php

namespace Quack\Protocol\Types;

use Quack\Protocol\Exceptions\ProtocolException;

/**
 * Converts DuckDB's packed BIT storage to and from its textual bit string.
 *
 * The storage starts with a padding count, followed by the bits packed
 * most-significant-bit first. Padding bits are stored as ones.
 */
final class BitString
{
    public static function fromStorage(string $raw): string
    {
        if ($raw === '') {
            return '';
        }
        $padding = ord($raw[0]);
        $bits = '';
        for ($index = 1, $length = strlen($raw); $index < $length; $index++) {
            $byte = ord($raw[$index]);
            for ($bit = 7; $bit >= 0; $bit--) {
                $bits .= ($byte & (1 << $bit)) !== 0 ? '1' : '0';
            }
        }
        if ($padding > strlen($bits)) {
            throw new ProtocolException('BIT padding exceeds the stored bit length');
        }

        return substr($bits, $padding);
    }

    public static function toStorage(string $bits): string
    {
        if (preg_match('/^[01]*$/', $bits) !== 1) {
            throw new ProtocolException('BIT value must contain only 0 and 1');
        }
        $padding = (8 - strlen($bits) % 8) % 8;
        $padded = str_repeat('1', $padding) . $bits;
        $raw = pack('C', $padding);
        for ($index = 0, $length = strlen($padded); $index < $length; $index += 8) {
            $byte = 0;
            for ($bit = 0; $bit < 8; $bit++) {
                $byte = ($byte << 1) | ($padded[$index + $bit] === '1' ? 1 : 0);
            }
            $raw .= pack('C', $byte);
        }

        return $raw;
    }
}
