<?php

namespace DuckDB\Quack\Chunk;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Exceptions\UnsupportedTypeException;
use DuckDB\Quack\Exceptions\Utf8Exception;
use DuckDB\Quack\Messages\MessageCodec;
use DuckDB\Quack\Transport\BinaryReader;
use DuckDB\Quack\Transport\BinaryWriter;
use DuckDB\Quack\Types\BigInt;
use DuckDB\Quack\Types\Bignum;
use DuckDB\Quack\Types\BitString;
use DuckDB\Quack\Types\DecimalValue;
use DuckDB\Quack\Types\HugeIntParts;
use DuckDB\Quack\Types\IntervalValue;
use DuckDB\Quack\Types\LogicalType;
use DuckDB\Quack\Types\LogicalTypeCodec;
use DuckDB\Quack\Types\LogicalTypeId;
use DuckDB\Quack\Types\PhysicalType;
use DuckDB\Quack\Types\TemporalFormatter;
use DuckDB\Quack\Types\TimestampUnit;
use DuckDB\Quack\Types\TimeUnit;
use DuckDB\Quack\Types\Value;
use DuckDB\Quack\Types\VariantDecoder;
use DuckDB\Quack\Types\VectorType;

/**
 * Encodes and decodes DuckDB data chunks and their column vectors.
 *
 * The layouts mirror the Rust SDK. Flat vectors are used for encoding; decoding
 * additionally understands constant, dictionary, and sequence vectors.
 */
final class DataChunkCodec
{
    public static function encodeDataChunkWrapper(BinaryWriter $writer, DataChunk $chunk): void
    {
        $writer->writeObject(function (BinaryWriter $object) use ($chunk): void {
            $object->writeField(300, fn (BinaryWriter $w) => self::encodeDataChunk($w, $chunk));
        });
    }

    /**
     * @param list<LogicalType> $types
     * @param list<string>      $resultNames
     */
    public static function decodeDataChunkWrapperToPhp(
        BinaryReader $reader,
        array $types,
        array $resultNames = [],
    ): DataChunk {
        return $reader->readObject(
            static fn (BinaryReader $object) => $object->readRequiredField(
                300,
                static fn (BinaryReader $r) => self::decodeDataChunkToPhp($r, $types, $resultNames),
            ),
        );
    }

    /**
     * Decodes a chunk straight to PHP values using the prepare-time schema.
     *
     * The chunk's own type list is consumed without allocating LogicalType
     * objects. It is validated against the supplied types, which are reused as
     * the decoded chunk's type metadata.
     *
     * @param list<LogicalType> $types
     * @param list<string>      $resultNames
     */
    public static function decodeDataChunkToPhp(
        BinaryReader $reader,
        array $types,
        array $resultNames = [],
    ): DataChunk {
        return $reader->readObject(function (BinaryReader $object) use ($types, $resultNames): DataChunk {
            $rowCount = $object->readRequiredField(
                100,
                static fn (BinaryReader $r) => $r->readUlebUsize(),
            );

            if ($types === []) {
                $decodedTypes = $object->readRequiredField(
                    101,
                    static fn (BinaryReader $r) => $r->readList(
                        static fn (BinaryReader $rr) => LogicalTypeCodec::decodeLogicalType($rr),
                    ),
                );
                /** @var list<LogicalType> $decodedTypes */
                return self::decodeColumns($object, $rowCount, $decodedTypes, $resultNames);
            }

            $chunkTypeIds = $object->readRequiredField(
                101,
                static fn (BinaryReader $r) => LogicalTypeCodec::skipLogicalTypeList($r),
            );
            if (count($chunkTypeIds) !== count($types)) {
                throw new ProtocolException(
                    'DataChunk declared ' . count($chunkTypeIds)
                    . ' types but the prepared result has ' . count($types),
                );
            }
            foreach ($types as $index => $type) {
                if (($chunkTypeIds[$index] ?? null) !== $type->id->value) {
                    throw new ProtocolException(
                        "DataChunk type {$index} does not match the prepared result type",
                    );
                }
            }

            return self::decodeColumns($object, $rowCount, $types, $resultNames);
        });
    }

    /**
     * @param list<LogicalType> $types
     * @param list<string>      $resultNames
     */
    private static function decodeColumns(
        BinaryReader $object,
        int $rowCount,
        array $types,
        array $resultNames = [],
    ): DataChunk {
        $columns = $object->readRequiredField(
            102,
            static fn (BinaryReader $r) => $r->readList(
                static function (BinaryReader $rr, int $index) use ($types, $rowCount): DecodedVector {
                    $logicalType = $types[$index] ?? null;
                    if ($logicalType === null) {
                        throw new ProtocolException(
                            "column vector {$index} has no matching logical type",
                        );
                    }
                    return self::decodeVector($rr, $logicalType, $rowCount);
                },
            ),
        );
        if (count($columns) !== count($types)) {
            throw new ProtocolException(
                'DataChunk declared ' . count($types) . ' types but serialized '
                . count($columns) . ' columns',
            );
        }
        return new DataChunk($rowCount, $types, $columns, $resultNames);
    }

    /**
     * Encoding still operates on Value objects; decoded chunks contain PHP
     * values, so reject them rather than failing deep inside the encoder.
     *
     * @param list<mixed> $values
     * @return list<Value>
     */
    private static function valueList(array $values, int $columnIndex): array
    {
        $result = [];
        foreach ($values as $value) {
            if (!$value instanceof Value) {
                throw new ProtocolException(
                    "column {$columnIndex} contains a decoded PHP value and cannot be re-encoded",
                );
            }
            $result[] = $value;
        }

        return $result;
    }

    public static function encodeDataChunk(BinaryWriter $writer, DataChunk $chunk): void
    {
        if (count($chunk->types) !== count($chunk->columns)) {
            throw new ProtocolException('DataChunk type count must match column count');
        }
        $writer->writeObject(function (BinaryWriter $object) use ($chunk): void {
            $object->writeField(100, static fn (BinaryWriter $w) => $w->writeUleb($chunk->rowCount));
            $object->writeField(101, static function (BinaryWriter $w) use ($chunk): void {
                $w->writeList(
                    $chunk->types,
                    static fn (BinaryWriter $ww, LogicalType $type) => LogicalTypeCodec::encodeLogicalType($ww, $type),
                );
            });
            $object->writeField(102, static function (BinaryWriter $w) use ($chunk): void {
                $w->writeList(
                    $chunk->columns,
                    static function (BinaryWriter $ww, DecodedVector $column, int $index) use ($chunk): void {
                        $logicalType = $chunk->types[$index] ?? null;
                        if ($logicalType === null) {
                            throw new ProtocolException("column {$index} has no logical type");
                        }
                        if (count($column->values) !== $chunk->rowCount) {
                            throw new ProtocolException(
                                "column {$index} has " . count($column->values)
                                . " values, expected {$chunk->rowCount}",
                            );
                        }
                        self::encodeVector(
                            $ww,
                            $logicalType,
                            self::valueList($column->values, $index),
                            $chunk->rowCount,
                        );
                    },
                );
            });
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

    /**
     * @param list<Value> $values
     */
    public static function encodeVector(
        BinaryWriter $writer,
        LogicalType $logicalType,
        array $values,
        int $count,
    ): void {
        $writer->writeObject(
            static fn (BinaryWriter $object) => self::encodeFlatVectorBody(
                $object,
                $logicalType,
                $values,
                $count,
            ),
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
            VectorType::Flat => self::decodeFlatVectorBody(
                $reader,
                $logicalType,
                $count,
                $vectorType,
            ),
            VectorType::Fsst => throw new UnsupportedTypeException(
                'FSST-compressed vectors are not supported',
            ),
            VectorType::Constant => self::decodeConstantVector($reader, $logicalType, $count),
            VectorType::Dictionary => self::decodeDictionaryVector($reader, $logicalType, $count),
            VectorType::Sequence => self::decodeSequenceVector($reader, $logicalType, $count),
        };
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
            static fn (BinaryReader $r) => self::readSelectionVector($r, $count),
        );
        $dictionaryCount = $reader->readRequiredField(
            92,
            static fn (BinaryReader $r) => $r->readUlebUsize(),
        );
        $dictionary = self::decodeVectorBody($reader, $logicalType, $dictionaryCount);

        $values = [];
        foreach ($selection as $index) {
            $values[] = $dictionary->values[$index]
                ?? throw new ProtocolException("dictionary selection {$index} is out of range");
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
        $values = [];
        for ($index = 0; $index < $count; $index++) {
            $current = $start->add($increment->mul(BigInt::fromInt($index)));
            $values[] = self::decodeSequenceValueToPhp($logicalType, $current);
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
                static fn (BinaryReader $r) => self::readValidityMask($r, $count),
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
                throw new ProtocolException(
                    'fixed-size vector data has ' . strlen($bytes)
                    . " bytes, expected {$byteLength}",
                );
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
            default => throw new UnsupportedTypeException(
                "variable-width physical type {$physicalType->name} is not supported",
            ),
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
            $values[] = self::isValid($validity, $index)
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
                            throw new ProtocolException(
                                "STRUCT child vector {$index} has no matching type metadata",
                            );
                        }
                        return self::decodeVector($rr, $child->logicalType, $count);
                    },
                );
            },
        );

        $values = [];
        for ($rowIndex = 0; $rowIndex < $count; $rowIndex++) {
            if (!self::isValid($validity, $rowIndex)) {
                $values[] = null;
                continue;
            }
            $row = [];
            foreach ($children as $childIndex => $child) {
                $childVector = $childVectors[$childIndex] ?? null;
                if ($childVector === null) {
                    throw new ProtocolException("STRUCT child {$childIndex} is incomplete");
                }
                $row[$child->name] = $childVector->values[$rowIndex] ?? null;
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
        return match ($logicalType->id) {
            LogicalTypeId::Union => self::unionToPhp($row, $logicalType),
            LogicalTypeId::Variant => VariantDecoder::fromPhp($row)->decode(),
            default => $row,
        };
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
            static fn (BinaryReader $r) => self::readListEntries($r, $count),
        );
        $childType = LogicalTypeCodec::getChildType($logicalType);
        $childVector = $reader->readRequiredField(
            106,
            static fn (BinaryReader $r) => self::decodeVector($r, $childType, $listSize),
        );

        $isMap = $logicalType->id === LogicalTypeId::Map;
        $values = [];
        foreach ($entries as $rowIndex => [$offset, $length]) {
            if (!self::isValid($validity, $rowIndex)) {
                $values[] = null;
                continue;
            }
            if ($offset > count($childVector->values) || $offset + $length > count($childVector->values)) {
                throw new ProtocolException(
                    "LIST entry {$rowIndex} overruns the child vector",
                );
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
            $key = $pair['key'] ?? null;
            if (!is_int($key) && !is_string($key)) {
                throw new ProtocolException(
                    'MAP key cannot be represented as a PHP array key',
                );
            }
            $result[$key] = $pair['value'] ?? null;
        }

        return $result;
    }

    /**
     * @param list<bool>|null $validity
     */
    private static function decodeArrayVector(
        BinaryReader $reader,
        LogicalType $logicalType,
        int $count,
        ?array $validity,
        VectorType $vectorType,
    ): DecodedVector {
        $arraySize = $reader->readRequiredField(
            103,
            static fn (BinaryReader $r) => $r->readUlebUsize(),
        );
        $expected = LogicalTypeCodec::getArraySize($logicalType);
        if ($arraySize !== $expected) {
            throw new ProtocolException(
                "ARRAY vector serialized size {$arraySize}, expected {$expected}",
            );
        }
        $childType = LogicalTypeCodec::getChildType($logicalType);
        $childVector = $reader->readRequiredField(
            104,
            static fn (BinaryReader $r) => self::decodeVector($r, $childType, $arraySize * $count),
        );

        $values = [];
        for ($rowIndex = 0; $rowIndex < $count; $rowIndex++) {
            if (!self::isValid($validity, $rowIndex)) {
                $values[] = null;
                continue;
            }
            $offset = $rowIndex * $arraySize;
            $values[] = array_slice($childVector->values, $offset, $arraySize);
        }
        return new DecodedVector($vectorType, $values);
    }

    /**
     * @param list<Value> $values
     */
    private static function encodeFlatVectorBody(
        BinaryWriter $writer,
        LogicalType $logicalType,
        array $values,
        int $count,
    ): void {
        if (count($values) !== $count) {
            throw new ProtocolException(
                'vector value count ' . count($values) . " does not match row count {$count}",
            );
        }
        if ($logicalType->id === LogicalTypeId::Geometry) {
            $writer->writeField(99, static fn (BinaryWriter $w) => $w->writeUleb(1));
        }

        $validity = [];
        foreach ($values as $value) {
            $validity[] = !$value->isNull();
        }
        $hasValidityMask = in_array(false, $validity, true);
        $writer->writeField(100, static fn (BinaryWriter $w) => $w->writeBool($hasValidityMask));
        if ($hasValidityMask) {
            $mask = self::writeValidityMask($validity);
            $writer->writeField(101, static fn (BinaryWriter $w) => $w->writeBlob($mask));
        }

        $physicalType = LogicalTypeCodec::getPhysicalType($logicalType);
        if (LogicalTypeCodec::isConstantSizePhysicalType($physicalType)) {
            $data = BinaryWriter::new();
            foreach ($values as $value) {
                self::encodeFixedValue($data, $logicalType, $physicalType, $value);
            }
            $bytes = $data->intoBytes();
            $writer->writeField(102, static fn (BinaryWriter $w) => $w->writeBlob($bytes));
            return;
        }

        match ($physicalType) {
            PhysicalType::Varchar => self::encodeStringVector($writer, $logicalType, $values),
            PhysicalType::Struct => self::encodeStructVector(
                $writer,
                $logicalType,
                $values,
                $count,
            ),
            PhysicalType::List => self::encodeListVector($writer, $logicalType, $values),
            PhysicalType::Array => self::encodeArrayVector($writer, $logicalType, $values),
            default => throw new UnsupportedTypeException(
                "cannot encode physical type {$physicalType->name}",
            ),
        };
    }

    /**
     * @param list<Value> $values
     */
    private static function encodeStringVector(
        BinaryWriter $writer,
        LogicalType $logicalType,
        array $values,
    ): void {
        $encoded = [];
        foreach ($values as $value) {
            $encoded[] = self::encodeStringLikeValue($logicalType, $value);
        }
        $writer->writeField(102, static function (BinaryWriter $w) use ($encoded): void {
            $w->writeList(
                $encoded,
                static fn (BinaryWriter $ww, string $bytes) => $ww->writeStringBytes($bytes),
            );
        });
    }

    /**
     * @param list<Value> $values
     */
    private static function encodeStructVector(
        BinaryWriter $writer,
        LogicalType $logicalType,
        array $values,
        int $count,
    ): void {
        $children = LogicalTypeCodec::getStructChildren($logicalType);
        $writer->writeField(103, static function (BinaryWriter $w) use ($children, $values, $count): void {
            $w->writeList(
                $children,
                static function (BinaryWriter $ww, $child) use ($values, $count): void {
                    $childValues = [];
                    foreach ($values as $value) {
                        $childValues[] = $value->kind === Value::STRUCT
                            ? ($value->asStruct()[$child->name] ?? Value::null())
                            : Value::null();
                    }
                    self::encodeVector($ww, $child->logicalType, $childValues, $count);
                },
            );
        });
    }

    /**
     * @param list<Value> $values
     */
    private static function encodeListVector(
        BinaryWriter $writer,
        LogicalType $logicalType,
        array $values,
    ): void {
        $childType = LogicalTypeCodec::getChildType($logicalType);
        $entries = [];
        $childValues = [];
        foreach ($values as $value) {
            if ($value->kind === Value::NULL) {
                $entries[] = [0, 0];
                continue;
            }
            if ($value->kind !== Value::LIST) {
                throw new ProtocolException('LIST/MAP values must be lists');
            }
            $items = $value->asList();
            $entries[] = [count($childValues), count($items)];
            foreach ($items as $item) {
                $childValues[] = $item;
            }
        }
        $writer->writeField(
            104,
            static fn (BinaryWriter $w) => $w->writeUleb(count($childValues)),
        );
        $writer->writeField(105, static fn (BinaryWriter $w) => self::writeListEntries($w, $entries));
        $writer->writeField(
            106,
            static fn (BinaryWriter $w) => self::encodeVector(
                $w,
                $childType,
                $childValues,
                count($childValues),
            ),
        );
    }

    /**
     * @param list<Value> $values
     */
    private static function encodeArrayVector(
        BinaryWriter $writer,
        LogicalType $logicalType,
        array $values,
    ): void {
        $childType = LogicalTypeCodec::getChildType($logicalType);
        $arraySize = LogicalTypeCodec::getArraySize($logicalType);
        $childValues = [];
        foreach ($values as $value) {
            if ($value->kind === Value::NULL) {
                for ($index = 0; $index < $arraySize; $index++) {
                    $childValues[] = Value::null();
                }
                continue;
            }
            if ($value->kind !== Value::LIST) {
                throw new ProtocolException('ARRAY values must be lists');
            }
            if (count($value->asList()) !== $arraySize) {
                throw new ProtocolException(
                    "ARRAY values must be lists of length {$arraySize}",
                );
            }
            foreach ($value->asList() as $item) {
                $childValues[] = $item;
            }
        }
        $writer->writeField(
            103,
            static fn (BinaryWriter $w) => $w->writeUleb($arraySize),
        );
        $writer->writeField(
            104,
            static fn (BinaryWriter $w) => self::encodeVector(
                $w,
                $childType,
                $childValues,
                count($childValues),
            ),
        );
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
    private static function readStringVectorData(
        BinaryReader $reader,
        int $count,
        ?array $validity,
    ): array {
        $byteDataLength = $reader->readOptionalField(
            107,
            static fn (BinaryReader $r) => $r->readUlebU64(),
            null,
        );
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

        $lengthData = $reader->readRequiredField(
            108,
            static fn (BinaryReader $r) => $r->readBlob(),
        );
        $byteData = $reader->readRequiredField(
            109,
            static fn (BinaryReader $r) => $r->readBlob(),
        );
        if (strlen($lengthData) !== $count * 4) {
            throw new ProtocolException(
                'string vector has ' . strlen($lengthData)
                . ' bytes of lengths, expected ' . ($count * 4) . " for {$count} rows",
            );
        }
        if (strlen($byteData) !== (int) $byteDataLength) {
            throw new ProtocolException(
                'string vector has ' . strlen($byteData)
                . " bytes of data, header declares {$byteDataLength}",
            );
        }

        /** @var list<int> $lengths */
        $lengths = array_values(unpack('V*', $lengthData) ?: []);
        $values = [];
        $offset = 0;
        foreach ($lengths as $index => $length) {
            if (!self::isValid($validity, $index)) {
                $values[] = '';
                continue;
            }
            if ($offset + $length > strlen($byteData)) {
                throw new ProtocolException(
                    "string {$index} of {$length} bytes overruns the vector's "
                    . strlen($byteData) . ' bytes of data',
                );
            }
            $values[] = substr($byteData, $offset, $length);
            $offset += $length;
        }
        if ($offset !== strlen($byteData)) {
            throw new ProtocolException(
                "string vector uses {$offset} of its " . strlen($byteData) . ' bytes of data',
            );
        }
        return $values;
    }

    /**
     * @param list<bool>|null $validity
     * @return list<mixed>
     */
    private static function decodeFixedValuesToPhp(
        LogicalType $logicalType,
        PhysicalType $physicalType,
        string $bytes,
        int $count,
        ?array $validity,
    ): array {
        $reader = new BinaryReader($bytes);
        $values = [];
        $size = LogicalTypeCodec::physicalTypeSize($physicalType);
        for ($index = 0; $index < $count; $index++) {
            if (!self::isValid($validity, $index)) {
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

    private static function decodeFixedValueToPhp(
        BinaryReader $reader,
        LogicalType $logicalType,
        PhysicalType $physicalType,
    ): mixed {
        return match ($physicalType) {
            PhysicalType::Bool => $reader->readFixedU8() !== 0,
            PhysicalType::Int8 => $reader->readFixedI8(),
            PhysicalType::UInt8 => self::decodeEnumOrNumberToPhp(
                $logicalType,
                $reader->readFixedU8(),
            ),
            PhysicalType::Int16 => self::decodeInt16OrDecimalToPhp($reader, $logicalType),
            PhysicalType::UInt16 => self::decodeEnumOrNumberToPhp(
                $logicalType,
                $reader->readFixedU16(),
            ),
            PhysicalType::Int32 => self::decodeInt32LogicalValueToPhp($reader, $logicalType),
            PhysicalType::UInt32 => self::decodeEnumOrNumberToPhp(
                $logicalType,
                $reader->readFixedU32(),
            ),
            PhysicalType::Int64 => self::decodeInt64LogicalValueToPhp(
                $logicalType,
                $reader->readFixedI64(),
            ),
            PhysicalType::UInt64 => $reader->readFixedU64(),
            PhysicalType::Float => $reader->readFixedF32(),
            PhysicalType::Double => $reader->readFixedF64(),
            PhysicalType::Int128 => self::decodeInt128ValueToPhp($reader, $logicalType),
            PhysicalType::UInt128 => self::decodeUInt128ValueToPhp($reader),
            PhysicalType::Interval => TemporalFormatter::formatInterval(
                new IntervalValue(
                    $reader->readFixedI32(),
                    $reader->readFixedI32(),
                    $reader->readFixedI64(),
                ),
            ),
            default => throw new UnsupportedTypeException(
                "cannot decode fixed physical type {$physicalType->name}",
            ),
        };
    }

    private static function decodeInt16OrDecimalToPhp(
        BinaryReader $reader,
        LogicalType $logicalType,
    ): int|float|string {
        $value = $reader->readFixedI16();
        if ($logicalType->id === LogicalTypeId::Decimal) {
            return self::decimalFromUnscaled($logicalType, $value)->toPhp();
        }
        return $value;
    }

    private static function decodeInt32LogicalValueToPhp(
        BinaryReader $reader,
        LogicalType $logicalType,
    ): int|float|string {
        $value = $reader->readFixedI32();
        if ($logicalType->id === LogicalTypeId::Date) {
            return TemporalFormatter::formatDate($value);
        }
        if ($logicalType->id === LogicalTypeId::Decimal) {
            return self::decimalFromUnscaled($logicalType, $value)->toPhp();
        }
        return $value;
    }

    private static function decodeInt128ValueToPhp(
        BinaryReader $reader,
        LogicalType $logicalType,
    ): int|float|string {
        $lower = $reader->readFixedU64();
        $upper = $reader->readFixedI64();
        $parts = new HugeIntParts($upper, $lower);
        if ($logicalType->id === LogicalTypeId::Uuid) {
            return $parts->uuid();
        }
        $value = $parts->toSignedBigInt();
        if ($logicalType->id === LogicalTypeId::Decimal) {
            return self::decimalFromUnscaled($logicalType, $value->toIntOrString())->toPhp();
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

    private static function decodeEnumOrNumberToPhp(
        LogicalType $logicalType,
        int|string $index,
    ): int|string {
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

    private static function encodeFixedValue(
        BinaryWriter $writer,
        LogicalType $logicalType,
        PhysicalType $physicalType,
        Value $value,
    ): void {
        if ($value->isNull()) {
            $writer->writeBytes(str_repeat("\0", LogicalTypeCodec::physicalTypeSize($physicalType)));
            return;
        }

        switch ($physicalType) {
            case PhysicalType::Bool:
                $writer->writeFixedU8(
                    $value->kind === Value::BOOL && $value->value === true ? 1 : 0,
                );
                return;
            case PhysicalType::Int8:
                self::writeSignedBits($writer, self::valueToBigInt($value), 8);
                return;
            case PhysicalType::UInt8:
                self::writeUnsignedBits(
                    $writer,
                    self::encodeEnumOrNumber($logicalType, $value),
                    8,
                );
                return;
            case PhysicalType::Int16:
                self::writeSignedBits(
                    $writer,
                    self::encodeDecimalOrInteger($logicalType, $value),
                    16,
                );
                return;
            case PhysicalType::UInt16:
                self::writeUnsignedBits(
                    $writer,
                    self::encodeEnumOrNumber($logicalType, $value),
                    16,
                );
                return;
            case PhysicalType::Int32:
                self::writeSignedBits(
                    $writer,
                    self::encodeDateDecimalOrInteger($logicalType, $value),
                    32,
                );
                return;
            case PhysicalType::UInt32:
                self::writeUnsignedBits(
                    $writer,
                    self::encodeEnumOrNumber($logicalType, $value),
                    32,
                );
                return;
            case PhysicalType::Int64:
                $writer->writeFixedI64(self::encodeInt64LogicalValue($logicalType, $value));
                return;
            case PhysicalType::UInt64:
                self::writeUnsignedBits($writer, self::valueToU128($value), 64);
                return;
            case PhysicalType::Float:
                $writer->writeFixedF32((float) self::valueToF64($value));
                return;
            case PhysicalType::Double:
                $writer->writeFixedF64(self::valueToF64($value));
                return;
            case PhysicalType::Int128:
                if ($logicalType->id === LogicalTypeId::Uuid) {
                    if ($value->kind !== Value::STRING) {
                        throw new ProtocolException('UUID values must be strings');
                    }
                    $parts = HugeIntParts::fromUuid($value->asString());
                } else {
                    $parts = HugeIntParts::fromSignedBigInt(
                        self::encodeInt128LogicalValue($logicalType, $value),
                    );
                }
                $writer->writeFixedU64(
                    BigInt::coerce($parts->lower)->lowBits(64),
                );
                $writer->writeFixedI64($parts->upper);
                return;
            case PhysicalType::UInt128:
                $bytes = self::valueToU128($value)->toBytesLE(16, false);
                $writer->writeBytes(substr($bytes, 0, 8));
                $writer->writeBytes(substr($bytes, 8, 8));
                return;
            case PhysicalType::Interval:
                if ($value->kind !== Value::INTERVAL) {
                    throw new ProtocolException('INTERVAL values must be tagged intervals');
                }
                $interval = $value->asInterval();
                $writer->writeFixedI32($interval->months);
                $writer->writeFixedI32($interval->days);
                $writer->writeFixedI64($interval->micros);
                return;
            default:
                throw new UnsupportedTypeException(
                    "cannot encode fixed physical type {$physicalType->name}",
                );
        }
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
            return self::typeStringFromStorage($raw);
        }
        if ($logicalType->id === LogicalTypeId::BigNum) {
            return Bignum::fromStorage($raw);
        }
        if ($logicalType->id === LogicalTypeId::Geometry) {
            return $raw;
        }
        if ($raw !== '' && preg_match('//u', $raw) !== 1) {
            throw new Utf8Exception('invalid UTF-8 string');
        }
        if (self::isJsonType($logicalType)) {
            return self::jsonToPhp($raw);
        }
        return $raw;
    }

    private static function isJsonType(LogicalType $logicalType): bool
    {
        return $logicalType->id === LogicalTypeId::Varchar
            && strcasecmp($logicalType->alias() ?? '', 'JSON') === 0;
    }

    private static function jsonToPhp(string $json): mixed
    {
        try {
            return json_decode(
                $json,
                true,
                512,
                JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING,
            );
        } catch (\JsonException $error) {
            throw new ProtocolException(
                'invalid JSON value: ' . $error->getMessage(),
            );
        }
    }

    private static function encodeStringLikeValue(LogicalType $logicalType, Value $value): string
    {
        if ($value->isNull()) {
            return '';
        }
        if ($logicalType->id === LogicalTypeId::Blob && $value->kind === Value::BYTES) {
            return $value->asBytes();
        }
        if ($logicalType->id === LogicalTypeId::Bit) {
            return BitString::toStorage(self::bitStringValue($value));
        }
        if ($value->kind === Value::STRING) {
            return $value->asString();
        }
        if ($value->kind === Value::BYTES) {
            return $value->asBytes();
        }
        return self::valueToDisplayString($value);
    }

    private static function bitStringValue(Value $value): string
    {
        return match ($value->kind) {
            Value::STRING => $value->asString(),
            Value::BYTES => $value->asBytes(),
            default => self::valueToDisplayString($value),
        };
    }

    /**
     * TYPE values store a serialized logical type, starting with field 100.
     */
    private static function typeStringFromStorage(string $raw): string
    {
        $reader = new BinaryReader($raw);
        $fieldId = $reader->readFixedU16();
        if ($fieldId !== 100) {
            throw new ProtocolException("unsupported TYPE value field {$fieldId}");
        }
        $type = LogicalTypeId::fromId($reader->readUlebInt());

        return self::logicalTypeName($type);
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
        return match ($logicalType->id) {
            LogicalTypeId::Time => TemporalFormatter::formatTime($value, TimeUnit::Micros),
            LogicalTypeId::TimeNs => TemporalFormatter::formatTime($value, TimeUnit::Nanos),
            LogicalTypeId::TimeTz => TemporalFormatter::formatTimeTz($value),
            LogicalTypeId::TimestampSec => TemporalFormatter::formatTimestamp(
                $value,
                TimestampUnit::Seconds,
            ),
            LogicalTypeId::TimestampMs => TemporalFormatter::formatTimestamp(
                $value,
                TimestampUnit::Millis,
            ),
            LogicalTypeId::Timestamp => TemporalFormatter::formatTimestamp(
                $value,
                TimestampUnit::Micros,
            ),
            LogicalTypeId::TimestampNs => TemporalFormatter::formatTimestamp(
                $value,
                TimestampUnit::Nanos,
            ),
            LogicalTypeId::TimestampTz => TemporalFormatter::formatTimestamp(
                $value,
                TimestampUnit::Micros,
                true,
            ),
            LogicalTypeId::Decimal => self::decimalFromUnscaled($logicalType, $value)->toPhp(),
            default => $value,
        };
    }

    private static function encodeInt64LogicalValue(LogicalType $logicalType, Value $value): BigInt
    {
        if ($logicalType->id === LogicalTypeId::Decimal) {
            return self::decimalToUnscaled($logicalType, $value);
        }
        return match ($value->kind) {
            Value::TIME => BigInt::fromInt($value->asTime()->value),
            Value::TIMESTAMP => BigInt::fromInt($value->asTimestamp()->value),
            Value::TIME_TZ => BigInt::fromInt($value->asTimeTz()->bits),
            default => self::valueToBigInt($value),
        };
    }

    private static function encodeInt128LogicalValue(
        LogicalType $logicalType,
        Value $value,
    ): BigInt {
        if ($logicalType->id === LogicalTypeId::Decimal) {
            return self::decimalToUnscaled($logicalType, $value);
        }
        return self::valueToBigInt($value);
    }

    private static function encodeDecimalOrInteger(
        LogicalType $logicalType,
        Value $value,
    ): BigInt {
        if ($logicalType->id === LogicalTypeId::Decimal) {
            return self::decimalToUnscaled($logicalType, $value);
        }
        return self::valueToBigInt($value);
    }

    private static function encodeDateDecimalOrInteger(
        LogicalType $logicalType,
        Value $value,
    ): BigInt {
        if ($logicalType->id === LogicalTypeId::Date) {
            return $value->kind === Value::DATE
                ? BigInt::fromInt($value->asDate()->days)
                : self::valueToBigInt($value);
        }
        return self::encodeDecimalOrInteger($logicalType, $value);
    }

    private static function decimalFromUnscaled(
        LogicalType $logicalType,
        BigInt|int|string $value,
    ): DecimalValue {
        [$width, $scale] = LogicalTypeCodec::getDecimalWidthAndScale($logicalType);
        $unscaled = $value instanceof BigInt ? $value->toIntOrString() : $value;
        return new DecimalValue($unscaled, $width, $scale);
    }

    private static function decimalToUnscaled(LogicalType $logicalType, Value $value): BigInt
    {
        return match ($value->kind) {
            Value::DECIMAL => BigInt::coerce($value->asDecimal()->value),
            Value::STRING => self::parseDecimalString($logicalType, $value->asString()),
            default => self::valueToBigInt($value),
        };
    }

    private static function parseDecimalString(LogicalType $logicalType, string $value): BigInt
    {
        [, $scale] = LogicalTypeCodec::getDecimalWidthAndScale($logicalType);
        $trimmed = trim($value);
        $negative = str_starts_with($trimmed, '-');
        $unsigned = ltrim($trimmed, '+-');
        $parts = explode('.', $unsigned, 2);
        $integer = $parts[0];
        $fraction = $parts[1] ?? '';
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $text = ($integer === '' ? '0' : $integer) . $fraction;
        $unscaled = BigInt::fromString($text);
        return $negative ? $unscaled->neg() : $unscaled;
    }

    private static function valueToBigInt(Value $value): BigInt
    {
        return match ($value->kind) {
            Value::BOOL => BigInt::fromInt($value->asBool() ? 1 : 0),
            Value::INT => BigInt::fromInt($value->asInt()),
            Value::UINT, Value::HUGEINT, Value::UHUGEINT => BigInt::coerce($value->asIntOrString()),
            Value::STRING => BigInt::fromString($value->asString()),
            default => throw new ProtocolException(
                "cannot convert value of kind {$value->kind} to integer",
            ),
        };
    }

    private static function valueToU128(Value $value): BigInt
    {
        $result = match ($value->kind) {
            Value::BOOL => BigInt::fromInt($value->asBool() ? 1 : 0),
            Value::INT => BigInt::fromInt($value->asInt()),
            Value::UINT, Value::HUGEINT, Value::UHUGEINT => BigInt::coerce($value->asIntOrString()),
            Value::STRING => BigInt::fromString($value->asString()),
            default => throw new ProtocolException(
                "cannot convert value of kind {$value->kind} to unsigned integer",
            ),
        };
        if ($result->isNegative()) {
            throw new ProtocolException(
                "cannot convert {$result} to unsigned integer",
            );
        }
        return $result;
    }

    private static function valueToF64(Value $value): float
    {
        return match ($value->kind) {
            Value::FLOAT, Value::DOUBLE => $value->asFloat(),
            Value::INT => (float) $value->asInt(),
            Value::UINT => (float) $value->asIntOrString(),
            Value::STRING => self::stringToFloat($value->asString()),
            default => throw new ProtocolException(
                "cannot convert value of kind {$value->kind} to float",
            ),
        };
    }

    private static function stringToFloat(string $value): float
    {
        if (!is_numeric($value)) {
            throw new ProtocolException("cannot convert {$value} to float");
        }

        return (float) $value;
    }

    private static function encodeEnumOrNumber(LogicalType $logicalType, Value $value): BigInt
    {
        if ($logicalType->id !== LogicalTypeId::Enum) {
            return self::valueToU128($value);
        }
        return match ($value->kind) {
            Value::UINT, Value::INT, Value::HUGEINT, Value::UHUGEINT => self::valueToU128($value),
            Value::STRING => self::enumIndexForString($logicalType, $value->asString()),
            default => throw new ProtocolException('ENUM values must be strings or integers'),
        };
    }

    private static function enumIndexForString(LogicalType $logicalType, string $value): BigInt
    {
        foreach (LogicalTypeCodec::getEnumValues($logicalType) as $index => $candidate) {
            if ($candidate === $value) {
                return BigInt::fromInt($index);
            }
        }
        throw new ProtocolException("unknown ENUM value {$value}");
    }

    private static function decodeSequenceValueToPhp(LogicalType $logicalType, BigInt $value): int|float|string
    {
        return match ($logicalType->id) {
            LogicalTypeId::Integer => self::asI64($value),
            LogicalTypeId::Date => TemporalFormatter::formatDate(self::asI32($value)),
            LogicalTypeId::BigInt => self::asI64($value),
            default => self::decodeInt64LogicalValueToPhp($logicalType, self::asI64($value)),
        };
    }

    private static function asI64(BigInt $value): int
    {
        return BigInt::fromBytesLE($value->lowBits(64)->toBytesLE(8, false), true)->toInt();
    }

    private static function asI32(BigInt $value): int
    {
        return BigInt::fromBytesLE($value->lowBits(32)->toBytesLE(4, false), true)->toInt();
    }

    private static function writeSignedBits(BinaryWriter $writer, BigInt $value, int $bits): void
    {
        $bytes = $value->lowBits($bits)->toBytesLE(intdiv($bits, 8), false);
        $writer->writeBytes($bytes);
    }

    private static function writeUnsignedBits(BinaryWriter $writer, BigInt $value, int $bits): void
    {
        $writer->writeBytes($value->lowBits($bits)->toBytesLE(intdiv($bits, 8), false));
    }

    private static function valueToDisplayString(Value $value): string
    {
        return match ($value->kind) {
            Value::NULL => '',
            Value::BOOL => $value->asBool() ? 'true' : 'false',
            Value::INT, Value::UINT, Value::HUGEINT, Value::UHUGEINT =>
                (string) $value->asIntOrString(),
            Value::FLOAT, Value::DOUBLE => self::formatFloat($value->asFloat()),
            Value::STRING => $value->asString(),
            Value::BYTES => $value->asBytes(),
            Value::DECIMAL => $value->asDecimal()->toString(),
            Value::DATE => (string) $value->asDate()->days,
            Value::TIME => (string) $value->asTime()->value,
            Value::TIME_TZ => (string) $value->asTimeTz()->bits,
            Value::TIMESTAMP => (string) $value->asTimestamp()->value,
            Value::INTERVAL => $value->asInterval()->months . ' ' . $value->asInterval()->days
                . ' ' . $value->asInterval()->micros,
            default => '',
        };
    }

    private static function formatFloat(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }
        return (string) $value;
    }

    /**
     * @return list<array{0:int,1:int}>
     */
    private static function readListEntries(BinaryReader $reader, int $count): array
    {
        $entries = $reader->readList(
            static fn (BinaryReader $r) => self::readListEntry($r),
        );
        if (count($entries) !== $count) {
            throw new ProtocolException(
                'LIST vector serialized ' . count($entries) . " entries for {$count} rows",
            );
        }
        return $entries;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function readListEntry(BinaryReader $reader): array
    {
        return $reader->readObject(static function (BinaryReader $object): array {
            $offset = $object->readRequiredField(
                100,
                static fn (BinaryReader $rr) => $rr->readUlebUsize(),
            );
            $length = $object->readRequiredField(
                101,
                static fn (BinaryReader $rr) => $rr->readUlebUsize(),
            );

            return [$offset, $length];
        });
    }

    /**
     * @param list<array{0:int,1:int}> $entries
     */
    private static function writeListEntries(BinaryWriter $writer, array $entries): void
    {
        $writer->writeList(
            $entries,
            static function (BinaryWriter $w, array $entry): void {
                $w->writeObject(static function (BinaryWriter $object) use ($entry): void {
                    $object->writeField(
                        100,
                        static fn (BinaryWriter $ww) => $ww->writeUleb($entry[0]),
                    );
                    $object->writeField(
                        101,
                        static fn (BinaryWriter $ww) => $ww->writeUleb($entry[1]),
                    );
                });
            },
        );
    }

    /**
     * @return list<int>
     */
    private static function readSelectionVector(BinaryReader $reader, int $count): array
    {
        $bytes = $reader->readBlob();
        $expected = $count * 4;
        if (strlen($bytes) !== $expected) {
            throw new ProtocolException(
                'selection vector has ' . strlen($bytes) . " bytes, expected {$expected}",
            );
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
    }

    /**
     * @return list<bool>
     */
    private static function readValidityMask(BinaryReader $reader, int $count): array
    {
        $expected = self::validityMaskSize($count);
        $bytes = $reader->readBlob();
        if (strlen($bytes) !== $expected) {
            throw new ProtocolException(
                'validity mask has ' . strlen($bytes) . " bytes, expected {$expected}",
            );
        }
        $values = [];
        for ($index = 0; $index < $count; $index++) {
            $byte = ord($bytes[intdiv($index, 8)] ?? "\0");
            $values[] = ($byte & (1 << ($index % 8))) !== 0;
        }
        return $values;
    }

    /**
     * @param list<bool> $validity
     */
    private static function writeValidityMask(array $validity): string
    {
        $bytes = str_repeat("\0", self::validityMaskSize(count($validity)));
        foreach ($validity as $index => $valid) {
            if ($valid) {
                $byteIndex = intdiv($index, 8);
                $bytes[$byteIndex] = chr((ord($bytes[$byteIndex]) | (1 << ($index % 8))) & 0xff);
            }
        }
        return $bytes;
    }

    private static function validityMaskSize(int $count): int
    {
        return intdiv($count + 63, 64) * 8;
    }

    /**
     * @param list<bool>|null $validity
     */
    private static function isValid(?array $validity, int $index): bool
    {
        return $validity === null || ($validity[$index] ?? false);
    }
}
