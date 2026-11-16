<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\UnsupportedTypeException;
use DuckDB\Quack\Exceptions\Utf8Exception;
use DuckDB\Quack\Messages\MessageCodec;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Types\BigInt;
use DuckDB\Quack\Types\Bignum;
use DuckDB\Quack\Types\BitString;
use DuckDB\Quack\Types\ChildType;
use DuckDB\Quack\Types\DecimalValue;
use DuckDB\Quack\Types\HugeIntParts;
use DuckDB\Quack\Types\IntervalValue;
use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\LogicalTypeCodec;
use DuckDB\Quack\Types\LogicalTypeId;
use DuckDB\Quack\Types\LogicalTypes;
use DuckDB\Quack\Types\PhysicalType;
use DuckDB\Quack\Types\TemporalFormatter;
use DuckDB\Quack\Types\TimestampUnit;
use DuckDB\Quack\Types\TimeUnit;
use DuckDB\Quack\Types\Value;
use DuckDB\Quack\Types\VariantDecoder;
use DuckDB\Quack\Types\VectorType;
use DuckDB\Quack\Types\WktFormatter;

/**
 * Encodes and decodes DuckDB data chunks and their column vectors.
 */
final class DataChunkCodec
{
    public static function decodeDataChunkWrapperToPhp(BinaryReader $reader): DataChunk
    {
        return $reader->readObject(
            static fn (BinaryReader $object) => $object->readRequiredField(
                300,
                static fn (BinaryReader $r) => self::decodeDataChunkToPhp($r),
            ),
        );
    }

    /**
     * Decodes a chunk straight to PHP values.
     *
     * The chunk carries its own type list, so no prepare-time schema is
     * needed.
     */
    public static function decodeDataChunkToPhp(BinaryReader $reader): DataChunk
    {
        return $reader->readObject(function (BinaryReader $object): DataChunk {
            $rowCount = $object->readRequiredField(100, static fn (BinaryReader $r) => $r->readUlebUsize());

            $decodedTypes = $object->readRequiredField(
                101,
                static fn (BinaryReader $r) => $r->readList(
                    static fn (BinaryReader $rr) => LogicalTypeCodec::decodeLogicalType($rr),
                ),
            );
            /** @var list<LogicalType> $decodedTypes */
            $columns = $object->readRequiredField(
                102,
                static fn (BinaryReader $r) => $r->readList(
                    static function (BinaryReader $rr, int $index) use ($decodedTypes, $rowCount): DecodedVector {
                        $logicalType = $decodedTypes[$index] ?? null;
                        if ($logicalType === null) {
                            throw new ProtocolException("column vector {$index} has no matching logical type");
                        }

                        return self::decodeVector($rr, $logicalType, $rowCount);
                    },
                ),
            );
            if (count($columns) !== count($decodedTypes)) {
                throw new ProtocolException('DataChunk declared ' . count($decodedTypes) . ' types but serialized ' . count($columns) . ' columns');
            }

            return new DataChunk($rowCount, $columns, [], []);
        });
    }

    public static function decodeVector(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
    ): DecodedVector {
        return $reader->readObject(
            static fn (BinaryReader $object) => self::decodeVectorBody($object, $logicalType, $count),
        );
    }

    private static function decodeVectorBody(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
    ): DecodedVector {
        $rawVectorType = $reader->readOptionalField(
            90,
            static fn (BinaryReader $r) => $r->readUlebU64(),
            VectorType::Flat->value,
        );
        $vectorType = VectorType::fromId((int) $rawVectorType);

        return match ($vectorType) {
            VectorType::Flat => self::decodeFlatVectorBody($reader, $logicalType, $count, $vectorType),
            VectorType::Fsst => throw new UnsupportedTypeException('FSST-compressed vectors are not supported'),
            VectorType::Constant => self::decodeConstantVector($reader, $logicalType, $count),
            VectorType::Dictionary => self::decodeDictionaryVector($reader, $logicalType, $count),
            VectorType::Sequence => self::decodeSequenceVector($reader, $logicalType, $count),
            VectorType::Shredded => self::decodeShreddedVector($reader, $logicalType, $count),
        };
    }

    /**
     * Decodes a SHREDDED_VECTOR, which serializes a
     * STRUCT(unshredded VARIANT, shredded <typed>) payload.
     *
     * The unshredded component is kept in its canonical array form so typed
     * nodes can resolve their untyped_value_index fallbacks; only the final
     * merged value is converted to native PHP.
     */
    private static function decodeShreddedVector(BinaryReader $reader, LogicalType $logicalType, int $count): DecodedVector
    {
        if ($logicalType->id !== LogicalTypeId::Variant) {
            throw new ProtocolException('shredded vectors can only decode VARIANT columns');
        }
        $shreddedType = $reader->readRequiredField(91, static fn (BinaryReader $r) => LogicalTypeCodec::decodeLogicalType($r));
        if ($shreddedType->id !== LogicalTypeId::Struct) {
            throw new ProtocolException('shredded vector data must be a struct');
        }
        $children = LogicalTypeCodec::getStructChildren($shreddedType);
        if (count($children) !== 2) {
            throw new ProtocolException('shredded vector data must have two children');
        }
        [$unshreddedChild, $shreddedChild] = $children;
        $unshreddedType = LogicalTypes::struct(
            LogicalTypeCodec::getStructChildren($unshreddedChild->logicalType),
        );
        $rawOuterType = LogicalTypes::struct([
            new ChildType($unshreddedChild->name, $unshreddedType),
            new ChildType($shreddedChild->name, $shreddedChild->logicalType),
        ]);
        $shreddedData = $reader->readRequiredField(92, static fn (BinaryReader $r) => self::decodeVector($r, $rawOuterType, $count));

        $values = [];
        foreach ($shreddedData->values as $row) {
            if (!is_array($row)) {
                $values[] = null;
                continue;
            }
            $unshreddedRow = $row[$unshreddedChild->name] ?? null;
            $unshredded = is_array($unshreddedRow) ? VariantDecoder::fromPhp(self::stringKeyedRow($unshreddedRow)) : null;
            $values[] = VariantDecoder::decodeShredded($shreddedChild->logicalType, $row[$shreddedChild->name] ?? null, $unshredded);
        }
        return new DecodedVector(VectorType::Shredded, $values);
    }

    /**
     * @param array<mixed,mixed> $row
     * @return array<string,mixed>
     */
    private static function stringKeyedRow(array $row): array
    {
        $result = [];
        foreach ($row as $key => $value) {
            if (!is_string($key)) {
                throw new ProtocolException('shredded VARIANT unshredded component is not a struct');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    private static function decodeConstantVector(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
    ): DecodedVector {
        $decoded = self::decodeVectorBody($reader, $logicalType, $count > 0 ? 1 : 0);
        $value = $decoded->values[0] ?? null;
        return new DecodedVector(
            VectorType::Constant,
            array_fill(0, $count, $value),
        );
    }

    private static function decodeDictionaryVector(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
    ): DecodedVector {
        $selection = $reader->readRequiredField(
            91,
            static function (BinaryReader $r) use ($count): array {
                $bytes = $r->readBlob();
                $expected = $count * 4;
                if (strlen($bytes) !== $expected) {
                    throw new ProtocolException('selection vector has ' . strlen($bytes) . " bytes, expected {$expected}");
                }
                $unpacked = unpack('V*', $bytes);
                if ($unpacked === false) {
                    throw new ProtocolException('selection vector could not be unpacked');
                }
                $values = [];
                foreach ($unpacked as $value) {
                    if (!is_int($value)) {
                        throw new ProtocolException('selection vector contained a non-integer');
                    }
                    $values[] = $value;
                }

                return $values;
            },
        );
        $dictionaryCount = $reader->readRequiredField(
            92,
            static fn (BinaryReader $r) => $r->readUlebUsize(),
        );
        $dictionary = self::decodeVectorBody($reader, $logicalType, $dictionaryCount);

        $values = [];
        foreach ($selection as $index) {
            $values[] = $dictionary->values[$index] ?? throw new ProtocolException("dictionary selection {$index} is out of range");
        }
        return new DecodedVector(VectorType::Dictionary, $values);
    }

    private static function decodeSequenceVector(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
    ): DecodedVector {
        $start = $reader->readRequiredField(
            91,
            static fn (BinaryReader $r) => $r->readSleb(),
        );
        $increment = $reader->readRequiredField(
            92,
            static fn (BinaryReader $r) => $r->readSleb(),
        );

        if ($count === 0) {
            return new DecodedVector(VectorType::Sequence, []);
        }

        // Sequence vectors describe an arithmetic progression. Use native
        // integers when the whole progression fits in an i64; this avoids
        // allocating BigInt objects for every row.
        $last = $start->add($increment->mul(BigInt::fromInt($count - 1)));
        $startInt = $start->toIntOrString();
        $incrementInt = $increment->toIntOrString();
        $lastInt = $last->toIntOrString();

        $values = [];
        if (is_int($startInt) && is_int($incrementInt) && is_int($lastInt)) {
            $current = $startInt;
            for ($index = 0; $index < $count; $index++) {
                if ($logicalType->id === LogicalTypeId::Integer
                    || $logicalType->id === LogicalTypeId::BigInt) {
                    $values[] = $current;
                } elseif ($logicalType->id === LogicalTypeId::Date) {
                    $low = $current & 0xFFFFFFFF;
                    $values[] = TemporalFormatter::formatDate(
                        $low >= 0x80000000 ? $low - 0x100000000 : $low,
                    );
                } else {
                    $values[] = self::decodeInt64LogicalValueToPhp($logicalType, $current);
                }
                if ($index + 1 < $count) {
                    $current += $incrementInt;
                }
            }
        } else {
            for ($index = 0; $index < $count; $index++) {
                $current = $start->add($increment->mul(BigInt::fromInt($index)));
                $values[] = match ($logicalType->id) {
                    LogicalTypeId::Integer => BigInt::fromBytesLE(
                        $current->lowBits(64)->toBytesLE(8, false),
                        true,
                    )->toInt(),
                    LogicalTypeId::Date => TemporalFormatter::formatDate(
                        BigInt::fromBytesLE(
                            $current->lowBits(32)->toBytesLE(4, false),
                            true,
                        )->toInt(),
                    ),
                    LogicalTypeId::BigInt => BigInt::fromBytesLE(
                        $current->lowBits(64)->toBytesLE(8, false),
                        true,
                    )->toInt(),
                    default => self::decodeInt64LogicalValueToPhp(
                        $logicalType,
                        BigInt::fromBytesLE(
                            $current->lowBits(64)->toBytesLE(8, false),
                            true,
                        )->toInt(),
                    ),
                };
            }
        }

        return new DecodedVector(VectorType::Sequence, $values);
    }

    private static function decodeFlatVectorBody(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
        VectorType $vectorType,
    ): DecodedVector {
        if ($logicalType->id === LogicalTypeId::Geometry && $reader->peekFieldId() === 99) {
            $reader->readRequiredField(99, static fn (BinaryReader $r) => $r->readUlebU64());
        }

        $hasValidityMask = $reader->readRequiredField(
            100,
            static fn (BinaryReader $r) => $r->readBool(),
        );
        $validity = $hasValidityMask
            ? $reader->readRequiredField(
                101,
                static function (BinaryReader $r) use ($count): array {
                    $expected = intdiv($count + 63, 64) * 8;
                    $bytes = $r->readBlob();
                    if (strlen($bytes) !== $expected) {
                        throw new ProtocolException('validity mask has ' . strlen($bytes) . " bytes, expected {$expected}");
                    }
                    $values = [];
                    for ($index = 0; $index < $count; $index++) {
                        $byte = ord($bytes[intdiv($index, 8)] ?? "\0");
                        $values[] = ($byte & (1 << ($index % 8))) !== 0;
                    }

                    return $values;
                },
            )
            : null;

        $physicalType = LogicalTypeCodec::getPhysicalType($logicalType);
        if (LogicalTypeCodec::isConstantSizePhysicalType($physicalType)) {
            $byteLength = LogicalTypeCodec::physicalTypeSize($physicalType) * $count;
            $bytes = $reader->readRequiredField(
                102,
                static fn (BinaryReader $r) => $r->readBlob(),
            );
            if (strlen($bytes) !== $byteLength) {
                throw new ProtocolException('fixed-size vector data has ' . strlen($bytes) . " bytes, expected {$byteLength}");
            }
            $values = self::decodeFixedValuesToPhp(
                $logicalType,
                $physicalType,
                $bytes,
                $count,
                $validity,
            );
            return new DecodedVector($vectorType, $values);
        }

        return match ($physicalType) {
            PhysicalType::Varchar => self::decodeStringVector(
                $reader,
                $logicalType,
                $count,
                $validity,
                $vectorType,
            ),
            PhysicalType::Struct => self::decodeStructVector(
                $reader,
                $logicalType,
                $count,
                $validity,
                $vectorType,
            ),
            PhysicalType::List => self::decodeListVector(
                $reader,
                $logicalType,
                $count,
                $validity,
                $vectorType,
            ),
            PhysicalType::Array => self::decodeArrayVector(
                $reader,
                $logicalType,
                $count,
                $validity,
                $vectorType,
            ),
            default => throw new UnsupportedTypeException("variable-width physical type {$physicalType->name} is not supported"),
        };
    }

    /**
     * @param list<bool>|null $validity
     */
    private static function decodeStringVector(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
        ?array $validity,
        VectorType $vectorType,
    ): DecodedVector {
        $rawValues = self::readStringVectorData($reader, $count, $validity);
        $values = [];
        foreach ($rawValues as $index => $raw) {
            $values[] = ($validity === null || ($validity[$index] ?? false))
                ? self::decodeStringLikeValueToPhp($logicalType, $raw)
                : null;
        }
        return new DecodedVector($vectorType, $values);
    }

    /**
     * @param list<bool>|null $validity
     */
    private static function decodeStructVector(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
        ?array $validity,
        VectorType $vectorType,
    ): DecodedVector {
        $children = LogicalTypeCodec::getStructChildren($logicalType);
        $childVectors = $reader->readRequiredField(
            103,
            static function (BinaryReader $r) use ($children, $count): array {
                return $r->readList(
                    static function (BinaryReader $rr, int $index) use ($children, $count): DecodedVector {
                        $child = $children[$index] ?? null;
                        if ($child === null) {
                            throw new ProtocolException("STRUCT child vector {$index} has no matching type metadata");
                        }
                        return self::decodeVector($rr, $child->logicalType, $count);
                    },
                );
            },
        );

        $values = [];
        for ($rowIndex = 0; $rowIndex < $count; $rowIndex++) {
            if ($validity !== null && !($validity[$rowIndex] ?? false)) {
                $values[] = null;
                continue;
            }
            $isTuple = $logicalType->id === LogicalTypeId::Tuple;
            $tuple = [];
            $row = [];
            foreach ($children as $childIndex => $child) {
                $childVector = $childVectors[$childIndex] ?? null;
                if ($childVector === null) {
                    throw new ProtocolException("STRUCT child {$childIndex} is incomplete");
                }
                $value = $childVector->values[$rowIndex] ?? null;
                if ($isTuple) {
                    // TUPLE children are unnamed, so keep them positional.
                    $tuple[] = $value;
                } else {
                    $row[$child->name] = $value;
                }
            }
            if ($isTuple) {
                $values[] = $tuple;
                continue;
            }
            $values[] = self::logicalStructToPhp($logicalType, $row);
        }
        return new DecodedVector($vectorType, $values);
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function logicalStructToPhp(LogicalType $logicalType, array $row): mixed
    {
        if (strcasecmp($logicalType->alias() ?? '', 'INET') === 0) {
            return self::inetStructToPhp($row);
        }

        return match ($logicalType->id) {
            LogicalTypeId::Union => self::unionToPhp($row, $logicalType),
            LogicalTypeId::Variant => VariantDecoder::fromPhp($row)->decode(),
            default => $row,
        };
    }

    /**
     * Formats the inet extension's STRUCT representation as its text form.
     *
     * @param array<string,mixed> $row
     */
    private static function inetStructToPhp(array $row): string
    {
        $family = $row['ip_type'] ?? null;
        $address = $row['address'] ?? null;
        $mask = $row['mask'] ?? null;
        if (!is_int($family) || !(is_int($address) || is_string($address)) || !is_int($mask)) {
            throw new ProtocolException('malformed INET value');
        }

        if ($family === 1) {
            $length = 4;
            $maxMask = 32;
            $addressValue = BigInt::coerce($address)->lowBits($length * 8);
        } elseif ($family === 2) {
            $length = 16;
            $maxMask = 128;
            $addressValue = BigInt::coerce($address)->lowBits($length * 8);
            // DuckDB flips the top bit of IPv6 addresses when storing them as
            // signed HUGEINTs so that ordering works; flip it back.
            $signBit = BigInt::pow2(127);
            $addressValue = $addressValue->compare($signBit) >= 0
                ? $addressValue->sub($signBit)
                : $addressValue->add($signBit);
        } else {
            throw new ProtocolException("unknown INET address family {$family}");
        }

        $bytes = $addressValue->toBytesLE($length, false);
        $text = inet_ntop(strrev($bytes));
        if ($text === false) {
            throw new ProtocolException('cannot format INET value');
        }

        return $mask === $maxMask ? $text : "{$text}/{$mask}";
    }

    /**
     * @param list<bool>|null $validity
     */
    private static function decodeListVector(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
        ?array $validity,
        VectorType $vectorType,
    ): DecodedVector {
        $listSize = $reader->readRequiredField(
            104,
            static fn (BinaryReader $r) => $r->readUlebUsize(),
        );
        $entries = $reader->readRequiredField(
            105,
            static function (BinaryReader $r) use ($count): array {
                $readEntry = static function (BinaryReader $object): array {
                    $offset = $object->readRequiredField(
                        100,
                        static fn (BinaryReader $entry) => $entry->readUlebUsize(),
                    );
                    $length = $object->readRequiredField(
                        101,
                        static fn (BinaryReader $entry) => $entry->readUlebUsize(),
                    );

                    return [$offset, $length];
                };
                $entries = $r->readList(
                    static fn (BinaryReader $rr) => $rr->readObject($readEntry),
                );
                if (count($entries) !== $count) {
                    throw new ProtocolException('LIST vector serialized ' . count($entries) . " entries for {$count} rows");
                }

                return $entries;
            },
        );
        $childType = LogicalTypeCodec::getChildType($logicalType);
        $childVector = $reader->readRequiredField(
            106,
            static fn (BinaryReader $r) => self::decodeVector($r, $childType, $listSize),
        );

        $isMap = $logicalType->id === LogicalTypeId::Map;
        $values = [];
        foreach ($entries as $rowIndex => [$offset, $length]) {
            if ($validity !== null && !($validity[$rowIndex] ?? false)) {
                $values[] = null;
                continue;
            }
            if ($offset > count($childVector->values) || $offset + $length > count($childVector->values)) {
                throw new ProtocolException("LIST entry {$rowIndex} overruns the child vector");
            }
            $items = array_slice($childVector->values, $offset, $length);
            $values[] = $isMap ? self::mapPairsToPhp($items, $logicalType) : $items;
        }
        return new DecodedVector($vectorType, $values);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function unionToPhp(array $row, LogicalType $logicalType): array
    {
        $tag = $row[''] ?? null;
        if (is_string($tag)) {
            if (preg_match('/^[0-9]+$/', $tag) !== 1) {
                throw new ProtocolException("invalid UNION tag {$tag}");
            }
            $tag = (int) $tag;
        }
        if (!is_int($tag)) {
            throw new ProtocolException('UNION value is missing its tag');
        }

        $children = LogicalTypeCodec::getStructChildren($logicalType);
        $member = $children[$tag + 1] ?? null;
        if ($member === null) {
            throw new ProtocolException("UNION tag {$tag} is out of range");
        }

        return [$member->name => $row[$member->name] ?? null];
    }

    /**
     * @param list<mixed> $pairs
     * @return array<int|string,mixed>
     */
    private static function mapPairsToPhp(array $pairs, LogicalType $logicalType): array
    {
        $result = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair)) {
                throw new ProtocolException('MAP entry is not a struct');
            }
            $key = Value::mapKeyToPhp($pair['key'] ?? null);
            $result[$key] = $pair['value'] ?? null;
        }

        return $result;
    }

    /**
     * @param list<bool>|null $validity
     */
    private static function decodeArrayVector(BinaryReader $reader, LogicalType $logicalType, int $count, ?array $validity, VectorType $vectorType): DecodedVector
    {
        $arraySize = $reader->readRequiredField(103, static fn (BinaryReader $r) => $r->readUlebUsize());
        $expected = LogicalTypeCodec::getArraySize($logicalType);
        if ($arraySize !== $expected) {
            throw new ProtocolException("ARRAY vector serialized size {$arraySize}, expected {$expected}");
        }
        $childType = LogicalTypeCodec::getChildType($logicalType);
        $childVector = $reader->readRequiredField(104, static fn (BinaryReader $r) => self::decodeVector($r, $childType, $arraySize * $count));
        $values = [];
        for ($rowIndex = 0; $rowIndex < $count; $rowIndex++) {
            if ($validity !== null && !($validity[$rowIndex] ?? false)) {
                $values[] = null;
                continue;
            }
            $offset = $rowIndex * $arraySize;
            $values[] = array_slice($childVector->values, $offset, $arraySize);
        }
        return new DecodedVector($vectorType, $values);
    }

    /**
     * Reads a string-like vector payload.
     *
     * DuckDB storage version 2.0 sends the byte length (107), a little-endian
     * u32 length per row (108), and the bytes of the valid rows (109). Older
     * servers send a list of strings in field 102.
     *
     * @param list<bool>|null $validity
     * @return list<string>
     */
    private static function readStringVectorData(BinaryReader $reader, int $count, ?array $validity): array
    {
        $byteDataLength = $reader->readOptionalField(107, static fn (BinaryReader $r) => $r->readUlebU64(), null);
        if ($byteDataLength !== null && (string) $byteDataLength === MessageCodec::OPTIONAL_INDEX_INVALID) {
            $byteDataLength = null;
        }
        if ($byteDataLength === null) {
            return $reader->readRequiredField(
                102,
                static fn (BinaryReader $r) => $r->readList(
                    static fn (BinaryReader $rr) => $rr->readStringBytes(),
                ),
            );
        }

        $lengthData = $reader->readRequiredField(108, static fn (BinaryReader $r) => $r->readBlob());
        $byteData = $reader->readRequiredField(109, static fn (BinaryReader $r) => $r->readBlob());
        if (strlen($lengthData) !== $count * 4) {
            throw new ProtocolException('string vector has ' . strlen($lengthData) . ' bytes of lengths, expected ' . ($count * 4) . " for {$count} rows");
        }
        if (strlen($byteData) !== (int) $byteDataLength) {
            throw new ProtocolException('string vector has ' . strlen($byteData) . " bytes of data, header declares {$byteDataLength}");
        }

        /** @var list<int> $lengths */
        $lengths = array_values(unpack('V*', $lengthData) ?: []);
        $values = [];
        $offset = 0;
        foreach ($lengths as $index => $length) {
            if ($validity !== null && !($validity[$index] ?? false)) {
                $values[] = '';
                continue;
            }
            if ($offset + $length > strlen($byteData)) {
                throw new ProtocolException("string {$index} of {$length} bytes overruns the vector's " . strlen($byteData) . ' bytes of data');
            }
            $values[] = substr($byteData, $offset, $length);
            $offset += $length;
        }
        if ($offset !== strlen($byteData)) {
            throw new ProtocolException("string vector uses {$offset} of its " . strlen($byteData) . ' bytes of data');
        }
        return $values;
    }

    /**
     * @param list<bool>|null $validity
     * @return list<mixed>
     */
    private static function decodeFixedValuesToPhp(LogicalType $logicalType, PhysicalType $physicalType, string $bytes, int $count, ?array $validity): array
    {
        $reader = new BinaryReader($bytes);
        $values = [];
        $size = LogicalTypeCodec::physicalTypeSize($physicalType);
        for ($index = 0; $index < $count; $index++) {
            if ($validity !== null && !($validity[$index] ?? false)) {
                // Null slots may contain arbitrary payload bytes, so skip them
                // instead of decoding a value that could be malformed.
                $reader->readBytes($size);
                $values[] = null;
                continue;
            }
            $values[] = self::decodeFixedValueToPhp($reader, $logicalType, $physicalType);
        }
        $reader->assertEof();
        return $values;
    }

    private static function decodeFixedValueToPhp(BinaryReader $reader, LogicalType $logicalType, PhysicalType $physicalType): mixed
    {
        return match ($physicalType) {
            PhysicalType::Bool => $reader->readFixedU8() !== 0,
            PhysicalType::Int8 => $reader->readFixedI8(),
            PhysicalType::UInt8 => self::decodeEnumOrNumberToPhp($logicalType, $reader->readFixedU8()),
            PhysicalType::Int16 => self::decodeInt16OrDecimalToPhp($reader, $logicalType),
            PhysicalType::UInt16 => self::decodeEnumOrNumberToPhp($logicalType, $reader->readFixedU16()),
            PhysicalType::Int32 => self::decodeInt32LogicalValueToPhp($reader, $logicalType),
            PhysicalType::UInt32 => self::decodeEnumOrNumberToPhp($logicalType, $reader->readFixedU32()),
            PhysicalType::Int64 => self::decodeInt64LogicalValueToPhp($logicalType, $reader->readFixedI64()),
            PhysicalType::UInt64 => $reader->readFixedU64(),
            PhysicalType::Float => $reader->readFixedF32(),
            PhysicalType::Double => $reader->readFixedF64(),
            PhysicalType::Int128 => self::decodeInt128ValueToPhp($reader, $logicalType),
            PhysicalType::UInt128 => self::decodeUInt128ValueToPhp($reader),
            PhysicalType::Interval => TemporalFormatter::formatInterval(new IntervalValue($reader->readFixedI32(), $reader->readFixedI32(), $reader->readFixedI64())),
            default => throw new UnsupportedTypeException("cannot decode fixed physical type {$physicalType->name}"),
        };
    }

    private static function decodeInt16OrDecimalToPhp(BinaryReader $reader, LogicalType $logicalType): int|float|string
    {
        $value = $reader->readFixedI16();
        if ($logicalType->id === LogicalTypeId::Decimal) {
            [$width, $scale] = LogicalTypeCodec::getDecimalWidthAndScale($logicalType);
            return (new DecimalValue($value, $width, $scale))->toPhp();
        }
        return $value;
    }

    private static function decodeInt32LogicalValueToPhp(BinaryReader $reader, LogicalType $logicalType): int|float|string
    {
        $value = $reader->readFixedI32();
        if ($logicalType->id === LogicalTypeId::Date) {
            return TemporalFormatter::formatDate($value);
        }
        if ($logicalType->id === LogicalTypeId::Decimal) {
            [$width, $scale] = LogicalTypeCodec::getDecimalWidthAndScale($logicalType);
            return (new DecimalValue($value, $width, $scale))->toPhp();
        }
        return $value;
    }

    private static function decodeInt128ValueToPhp(BinaryReader $reader, LogicalType $logicalType): int|float|string
    {
        $lower = $reader->readFixedU64();
        $upper = $reader->readFixedI64();
        $parts = new HugeIntParts($upper, $lower);
        if ($logicalType->id === LogicalTypeId::Uuid) {
            return $parts->uuid();
        }
        $value = $parts->toSignedBigInt();
        if ($logicalType->id === LogicalTypeId::Decimal) {
            [$width, $scale] = LogicalTypeCodec::getDecimalWidthAndScale($logicalType);
            return (new DecimalValue($value->toIntOrString(), $width, $scale))->toPhp();
        }
        return $value->toIntOrString();
    }

    private static function decodeUInt128ValueToPhp(BinaryReader $reader): int|string
    {
        $lower = $reader->readFixedU64();
        $upper = $reader->readFixedI64();
        $parts = new HugeIntParts($upper, $lower);
        return $parts->toUnsignedBigInt()->toIntOrString();
    }

    private static function decodeEnumOrNumberToPhp(LogicalType $logicalType, int|string $index): int|string
    {
        if ($logicalType->id !== LogicalTypeId::Enum) {
            return $index;
        }
        $values = LogicalTypeCodec::getEnumValues($logicalType);
        $position = (int) $index;
        if ((string) $position !== (string) $index || !isset($values[$position])) {
            throw new ProtocolException("ENUM index {$index} is out of range");
        }
        return $values[$position];
    }

    private static function decodeStringLikeValueToPhp(LogicalType $logicalType, string $raw): mixed
    {
        if ($logicalType->id === LogicalTypeId::Blob) {
            return $raw;
        }
        if ($logicalType->id === LogicalTypeId::Bit) {
            return BitString::fromStorage($raw);
        }
        if ($logicalType->id === LogicalTypeId::Type) {
            // TYPE values store a serialized logical type, starting with field 100.
            $reader = new BinaryReader($raw);
            $fieldId = $reader->readFixedU16();
            if ($fieldId !== 100) {
                throw new ProtocolException("unsupported TYPE value field {$fieldId}");
            }
            return self::logicalTypeName(LogicalTypeId::fromId($reader->readUlebInt()));
        }
        if ($logicalType->id === LogicalTypeId::BigNum) {
            return Bignum::fromStorage($raw);
        }
        if ($logicalType->id === LogicalTypeId::Geometry) {
            return WktFormatter::fromWkb($raw);
        }
        if ($raw !== '' && preg_match('//u', $raw) !== 1) {
            throw new Utf8Exception('invalid UTF-8 string');
        }
        if ($logicalType->id === LogicalTypeId::Varchar && strcasecmp($logicalType->alias() ?? '', 'JSON') === 0) {
            try {
                return json_decode($raw, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (\JsonException $error) {
                // DuckDB writes non-finite doubles as bare tokens, which are not
                // valid JSON but are returned verbatim by PDO_duckdb.
                $token = trim($raw);
                if ($token === 'NaN' || $token === 'Infinity' || $token === '-Infinity') {
                    return $token;
                }
                throw new ProtocolException('invalid JSON value: ' . $error->getMessage());
            }
        }
        return $raw;
    }

    private static function logicalTypeName(LogicalTypeId $type): string
    {
        return match ($type) {
            LogicalTypeId::Invalid => 'INVALID',
            LogicalTypeId::SqlNull => 'NULL',
            LogicalTypeId::Unknown => 'UNKNOWN',
            LogicalTypeId::Any => 'ANY',
            LogicalTypeId::Unbound => 'UNBOUND',
            LogicalTypeId::Template => 'TEMPLATE',
            LogicalTypeId::Type => 'TYPE',
            LogicalTypeId::Boolean => 'BOOLEAN',
            LogicalTypeId::TinyInt => 'TINYINT',
            LogicalTypeId::SmallInt => 'SMALLINT',
            LogicalTypeId::Integer => 'INTEGER',
            LogicalTypeId::BigInt => 'BIGINT',
            LogicalTypeId::Date => 'DATE',
            LogicalTypeId::Time => 'TIME',
            LogicalTypeId::TimestampSec => 'TIMESTAMP_S',
            LogicalTypeId::TimestampMs => 'TIMESTAMP_MS',
            LogicalTypeId::Timestamp => 'TIMESTAMP',
            LogicalTypeId::TimestampNs => 'TIMESTAMP_NS',
            LogicalTypeId::Decimal => 'DECIMAL',
            LogicalTypeId::Float => 'FLOAT',
            LogicalTypeId::Double => 'DOUBLE',
            LogicalTypeId::Char => 'CHAR',
            LogicalTypeId::Varchar => 'VARCHAR',
            LogicalTypeId::Blob => 'BLOB',
            LogicalTypeId::Interval => 'INTERVAL',
            LogicalTypeId::UTinyInt => 'UTINYINT',
            LogicalTypeId::USmallInt => 'USMALLINT',
            LogicalTypeId::UInteger => 'UINTEGER',
            LogicalTypeId::UBigInt => 'UBIGINT',
            LogicalTypeId::TimestampTz => 'TIMESTAMP WITH TIME ZONE',
            LogicalTypeId::TimestampTzNs => 'TIMESTAMP_TZ_NS',
            LogicalTypeId::TimeTz => 'TIME WITH TIME ZONE',
            LogicalTypeId::TimeNs => 'TIME_NS',
            LogicalTypeId::Bit => 'BIT',
            LogicalTypeId::StringLiteral => 'STRING_LITERAL',
            LogicalTypeId::IntegerLiteral => 'INTEGER_LITERAL',
            LogicalTypeId::BigNum => 'BIGNUM',
            LogicalTypeId::UHugeInt => 'UHUGEINT',
            LogicalTypeId::HugeInt => 'HUGEINT',
            LogicalTypeId::Pointer => 'POINTER',
            LogicalTypeId::Validity => 'VALIDITY',
            LogicalTypeId::Uuid => 'UUID',
            LogicalTypeId::Geometry => 'GEOMETRY',
            LogicalTypeId::Struct => 'STRUCT',
            LogicalTypeId::List => 'LIST',
            LogicalTypeId::Map => 'MAP',
            LogicalTypeId::Table => 'TABLE',
            LogicalTypeId::Enum => 'ENUM',
            LogicalTypeId::AggregateState => 'AGGREGATE_STATE',
            LogicalTypeId::Lambda => 'LAMBDA',
            LogicalTypeId::Union => 'UNION',
            LogicalTypeId::Array => 'ARRAY',
            LogicalTypeId::Variant => 'VARIANT',
            LogicalTypeId::Tuple => 'TUPLE',
        };
    }

    private static function decodeInt64LogicalValueToPhp(LogicalType $logicalType, int $value): int|float|string
    {
        if ($logicalType->id === LogicalTypeId::Decimal) {
            [$width, $scale] = LogicalTypeCodec::getDecimalWidthAndScale($logicalType);
            return (new DecimalValue($value, $width, $scale))->toPhp();
        }

        return match ($logicalType->id) {
            LogicalTypeId::Time => TemporalFormatter::formatTime($value, TimeUnit::Micros),
            LogicalTypeId::TimeNs => TemporalFormatter::formatTime($value, TimeUnit::Nanos),
            LogicalTypeId::TimeTz => TemporalFormatter::formatTimeTz($value),
            LogicalTypeId::TimestampSec => TemporalFormatter::formatTimestamp($value, TimestampUnit::Seconds),
            LogicalTypeId::TimestampMs => TemporalFormatter::formatTimestamp($value, TimestampUnit::Millis),
            LogicalTypeId::Timestamp => TemporalFormatter::formatTimestamp($value, TimestampUnit::Micros),
            LogicalTypeId::TimestampNs => TemporalFormatter::formatTimestamp($value, TimestampUnit::Nanos),
            LogicalTypeId::TimestampTz => TemporalFormatter::formatTimestamp($value, TimestampUnit::Micros, true),
            LogicalTypeId::TimestampTzNs => TemporalFormatter::formatTimestamp($value, TimestampUnit::Nanos, true),
            default => $value,
        };
    }
}
