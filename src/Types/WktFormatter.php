<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Transport\BinaryReader;

/**
 * Formats an ISO WKB geometry value as WKT.
 *
 * The output mirrors DuckDB's Geometry::ToString / ToStringRecursive, which
 * backs ST_AsText. In particular, MULTIPOINT parts are not wrapped in
 * parentheses, empty parts inside collections render as EMPTY, and numbers use
 * the shortest round-trip representation with fmt's fixed/scientific threshold.
 */
final class WktFormatter
{
    private const MAX_RECURSION_DEPTH = 16;
    private const POINT = 1;
    private const LINESTRING = 2;
    private const POLYGON = 3;
    private const MULTIPOINT = 4;
    private const MULTILINESTRING = 5;
    private const MULTIPOLYGON = 6;
    private const GEOMETRYCOLLECTION = 7;

    public static function fromWkb(string $wkb): string
    {
        return self::format(new BinaryReader($wkb), 0, false, false);
    }

    private static function format(
        BinaryReader $reader,
        int $depth,
        bool $parentHasZ,
        bool $parentHasM,
    ): string {
        if ($depth === self::MAX_RECURSION_DEPTH) {
            throw new ProtocolException('geometry exceeds maximum recursion depth of ' . self::MAX_RECURSION_DEPTH);
        }

        [$type, $hasZ, $hasM] = self::readHeader($reader);
        if ($depth !== 0 && ($hasZ !== $parentHasZ || $hasM !== $parentHasM)) {
            throw new ProtocolException('geometry has inconsistent Z/M dimensions');
        }

        $dims = 2 + ($hasZ ? 1 : 0) + ($hasM ? 1 : 0);
        $name = self::typeName($type) . ($hasZ ? ($hasM ? ' ZM' : ' Z') : ($hasM ? ' M' : ''));

        switch ($type) {
            case self::POINT:
                $values = self::readVertex($reader, $dims);
                return self::allNan($values) ? $name . ' EMPTY' : $name . ' (' . self::formatVertex($values) . ')';
            case self::LINESTRING:
                $count = $reader->readFixedU32();
                if ($count === 0) {
                    return $name . ' EMPTY';
                }
                $vertices = [];
                for ($index = 0; $index < $count; $index++) {
                    $vertices[] = self::formatVertex(self::readVertex($reader, $dims));
                }
                return $name . ' (' . implode(', ', $vertices) . ')';
            case self::POLYGON:
                $rings = self::readRings($reader, $dims);
                return $rings === null ? $name . ' EMPTY' : $name . ' (' . implode(', ', $rings) . ')';
            case self::MULTIPOINT:
                $count = $reader->readFixedU32();
                if ($count === 0) {
                    return $name . ' EMPTY';
                }
                $points = [];
                for ($index = 0; $index < $count; $index++) {
                    [$partType, $partHasZ, $partHasM] = self::readHeader($reader);
                    if ($partType !== self::POINT) {
                        throw new ProtocolException('expected POINT in MULTIPOINT but got ' . $partType);
                    }
                    if ($partHasZ !== $hasZ || $partHasM !== $hasM) {
                        throw new ProtocolException('geometry has inconsistent Z/M dimensions in MULTIPOINT');
                    }
                    $values = self::readVertex($reader, $dims);
                    $points[] = self::allNan($values) ? 'EMPTY' : self::formatVertex($values);
                }
                return $name . ' (' . implode(', ', $points) . ')';
            case self::MULTILINESTRING:
                $count = $reader->readFixedU32();
                if ($count === 0) {
                    return $name . ' EMPTY';
                }
                $lines = [];
                for ($index = 0; $index < $count; $index++) {
                    [$partType, $partHasZ, $partHasM] = self::readHeader($reader);
                    if ($partType !== self::LINESTRING) {
                        throw new ProtocolException('expected LINESTRING in MULTILINESTRING but got ' . $partType);
                    }
                    if ($partHasZ !== $hasZ || $partHasM !== $hasM) {
                        throw new ProtocolException('geometry has inconsistent Z/M dimensions in MULTILINESTRING');
                    }
                    $vertexCount = $reader->readFixedU32();
                    if ($vertexCount === 0) {
                        $lines[] = 'EMPTY';
                        continue;
                    }
                    $vertices = [];
                    for ($vertex = 0; $vertex < $vertexCount; $vertex++) {
                        $vertices[] = self::formatVertex(self::readVertex($reader, $dims));
                    }
                    $lines[] = '(' . implode(', ', $vertices) . ')';
                }
                return $name . ' (' . implode(', ', $lines) . ')';
            case self::MULTIPOLYGON:
                $count = $reader->readFixedU32();
                if ($count === 0) {
                    return $name . ' EMPTY';
                }
                $polygons = [];
                for ($index = 0; $index < $count; $index++) {
                    [$partType, $partHasZ, $partHasM] = self::readHeader($reader);
                    if ($partType !== self::POLYGON) {
                        throw new ProtocolException('expected POLYGON in MULTIPOLYGON but got ' . $partType);
                    }
                    if ($partHasZ !== $hasZ || $partHasM !== $hasM) {
                        throw new ProtocolException('geometry has inconsistent Z/M dimensions in MULTIPOLYGON');
                    }
                    $rings = self::readRings($reader, $dims);
                    $polygons[] = $rings === null ? 'EMPTY' : '(' . implode(', ', $rings) . ')';
                }
                return $name . ' (' . implode(', ', $polygons) . ')';

            case self::GEOMETRYCOLLECTION:
                $count = $reader->readFixedU32();
                if ($count === 0) {
                    return $name . ' EMPTY';
                }
                $parts = [];
                for ($index = 0; $index < $count; $index++) {
                    $parts[] = self::format($reader, $depth + 1, $hasZ, $hasM);
                }
                return $name . ' (' . implode(', ', $parts) . ')';
        }
        throw new ProtocolException("unsupported geometry type {$type} in WKB");
    }

    /**
     * @return array{int, bool, bool}
     */
    private static function readHeader(BinaryReader $reader): array
    {
        $byteOrder = $reader->readByte();
        if ($byteOrder !== 1) {
            throw new ProtocolException("unsupported WKB byte order {$byteOrder}");
        }
        $meta = $reader->readFixedU32();
        $type = ($meta & 0xFFFF) % 1000;
        $flag = intdiv($meta & 0xFFFF, 1000);
        return [$type, ($flag & 0x01) !== 0, ($flag & 0x02) !== 0];
    }

    /**
     * Reads one polygon. Returns null when the polygon itself is empty, and a
     * list of ring strings otherwise. Empty rings are rendered as EMPTY.
     *
     * @return list<string>|null
     */
    private static function readRings(BinaryReader $reader, int $dims): ?array
    {
        $count = $reader->readFixedU32();
        if ($count === 0) {
            return null;
        }
        $rings = [];
        for ($index = 0; $index < $count; $index++) {
            $vertexCount = $reader->readFixedU32();
            if ($vertexCount === 0) {
                $rings[] = 'EMPTY';
                continue;
            }
            $vertices = [];
            for ($vertex = 0; $vertex < $vertexCount; $vertex++) {
                $vertices[] = self::formatVertex(self::readVertex($reader, $dims));
            }
            $rings[] = '(' . implode(', ', $vertices) . ')';
        }
        return $rings;
    }

    /**
     * @return list<float>
     */
    private static function readVertex(BinaryReader $reader, int $dims): array
    {
        $values = [];
        for ($index = 0; $index < $dims; $index++) {
            $values[] = $reader->readFixedF64();
        }
        return $values;
    }

    /**
     * @param list<float> $values
     */
    private static function formatVertex(array $values): string
    {
        return implode(' ', array_map(self::formatDouble(...), $values));
    }

    /**
     * @param list<float> $values
     */
    private static function allNan(array $values): bool
    {
        foreach ($values as $value) {
            if (!is_nan($value)) {
                return false;
            }
        }
        return true;
    }

    private static function typeName(int $type): string
    {
        return match ($type) {
            self::POINT => 'POINT',
            self::LINESTRING => 'LINESTRING',
            self::POLYGON => 'POLYGON',
            self::MULTIPOINT => 'MULTIPOINT',
            self::MULTILINESTRING => 'MULTILINESTRING',
            self::MULTIPOLYGON => 'MULTIPOLYGON',
            self::GEOMETRYCOLLECTION => 'GEOMETRYCOLLECTION',
            default => throw new ProtocolException("unsupported geometry type {$type} in WKB"),
        };
    }

    /**
     * Renders a double like fmt's default float formatter: shortest round-trip
     * digits, fixed notation while the decimal exponent is in [-4, 16), and
     * scientific notation otherwise.
     */
    private static function formatDouble(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }
        if (is_infinite($value)) {
            return $value < 0 ? '-inf' : 'inf';
        }
        if ($value === 0.0) {
            return fdiv(1.0, $value) < 0.0 ? '-0' : '0';
        }
        $repr = json_encode($value, JSON_THROW_ON_ERROR);
        $negative = str_starts_with($repr, '-');
        if ($negative) {
            $repr = substr($repr, 1);
        }
        $exponent = 0;
        $exponentPosition = strpos($repr, 'e');
        if ($exponentPosition !== false) {
            $exponent = (int) substr($repr, $exponentPosition + 1);
            $repr = substr($repr, 0, $exponentPosition);
        }
        $pointPosition = strpos($repr, '.');
        if ($pointPosition === false) {
            $pointPosition = strlen($repr);
            $digits = $repr;
        } else {
            $digits = substr($repr, 0, $pointPosition) . substr($repr, $pointPosition + 1);
        }
        $pointPosition += $exponent;
        $leadingZeros = strspn($digits, '0');
        $digits = substr($digits, $leadingZeros);
        $pointPosition -= $leadingZeros;

        // Scientific mantissas produced by json_encode may keep a trailing
        // zero, e.g. "1.0e+20". Shortest digits are used for the WKT output.
        $digits = rtrim($digits, '0');
        $exponent = $pointPosition - 1;
        if ($exponent >= -4 && $exponent < 16) {
            if ($exponent < 0) {
                $formatted = '0.' . str_repeat('0', -$exponent - 1) . $digits;
            } elseif (strlen($digits) <= $exponent + 1) {
                $formatted = $digits . str_repeat('0', $exponent + 1 - strlen($digits));
            } else {
                $formatted = substr($digits, 0, $exponent + 1) . '.' . substr($digits, $exponent + 1);
            }
        } else {
            $mantissa = $digits[0] . (strlen($digits) > 1 ? '.' . substr($digits, 1) : '');
            $exponentText = ($exponent < 0 ? '-' : '+') . str_pad((string) abs($exponent), 2, '0', STR_PAD_LEFT);
            $formatted = $mantissa . 'e' . $exponentText;
        }
        return $negative ? '-' . $formatted : $formatted;
    }
}
