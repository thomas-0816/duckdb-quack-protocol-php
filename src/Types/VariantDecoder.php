<?php

namespace Quack\Protocol\Types;

use Quack\Protocol\Exceptions\ProtocolException;

/**
 * Decodes DuckDB's shredded VARIANT representation into native PHP values.
 *
 * A VARIANT is transported as a struct of four parallel collections:
 * keys, children, values, and a data blob. Child and value indices point into
 * those collections, while each value's byte offset points into the blob.
 */
final class VariantDecoder
{
    private const VARIANT_NULL = 0;
    private const BOOL_TRUE = 1;
    private const BOOL_FALSE = 2;
    private const INT8 = 3;
    private const INT16 = 4;
    private const INT32 = 5;
    private const INT64 = 6;
    private const INT128 = 7;
    private const UINT8 = 8;
    private const UINT16 = 9;
    private const UINT32 = 10;
    private const UINT64 = 11;
    private const UINT128 = 12;
    private const FLOAT = 13;
    private const DOUBLE = 14;
    private const DECIMAL = 15;
    private const VARCHAR = 16;
    private const BLOB = 17;
    private const UUID = 18;
    private const DATE = 19;
    private const TIME_MICROS = 20;
    private const TIME_NANOS = 21;
    private const TIMESTAMP_SEC = 22;
    private const TIMESTAMP_MILLIS = 23;
    private const TIMESTAMP_MICROS = 24;
    private const TIMESTAMP_NANOS = 25;
    private const TIME_MICROS_TZ = 26;
    private const TIMESTAMP_MICROS_TZ = 27;
    private const INTERVAL = 28;
    private const OBJECT = 29;
    private const ARRAY = 30;
    private const BIGNUM = 31;
    private const BITSTRING = 32;
    private const GEOMETRY = 33;
    private const TIMESTAMP_NANOS_TZ = 34;

    /**
     * @param list<string>                                        $keys
     * @param list<array{keys_index:?int,values_index:int}>        $children
     * @param list<array{type_id:int,byte_offset:int}>             $values
     */
    private function __construct(
        private readonly array $keys,
        private readonly array $children,
        private readonly array $values,
        private readonly string $data,
    ) {
    }

    public static function fromValue(Value $value): self
    {
        $fields = $value->asStruct();

        $keys = [];
        foreach (self::requiredList($fields, 'keys') as $key) {
            $keys[] = $key->asString();
        }

        $children = [];
        foreach (self::requiredList($fields, 'children') as $child) {
            $childFields = $child->asStruct();
            $children[] = [
                'keys_index' => self::optionalIndex($childFields['keys_index'] ?? null),
                'values_index' => self::requiredIndex(
                    $childFields['values_index'] ?? null,
                    'values_index',
                ),
            ];
        }

        $values = [];
        foreach (self::requiredList($fields, 'values') as $entry) {
            $entryFields = $entry->asStruct();
            $values[] = [
                'type_id' => self::requiredIndex(
                    $entryFields['type_id'] ?? null,
                    'type_id',
                ),
                'byte_offset' => self::requiredIndex(
                    $entryFields['byte_offset'] ?? null,
                    'byte_offset',
                ),
            ];
        }

        $dataValue = $fields['data'] ?? throw new ProtocolException(
            'VARIANT is missing the data field',
        );
        $data = $dataValue->isNull() ? '' : $dataValue->asBytes();

        return new self($keys, $children, $values, $data);
    }

    public function decode(): mixed
    {
        if ($this->values === []) {
            throw new ProtocolException('VARIANT has no root value');
        }

        return $this->decodeValue(0);
    }

    private function decodeValue(int $valueIndex): mixed
    {
        $entry = $this->values[$valueIndex] ?? null;
        if ($entry === null) {
            throw new ProtocolException("VARIANT value index {$valueIndex} is out of range");
        }
        $typeId = $entry['type_id'];
        $offset = $entry['byte_offset'];

        return match ($typeId) {
            self::VARIANT_NULL => null,
            self::BOOL_TRUE => true,
            self::BOOL_FALSE => false,
            self::INT8 => $this->readSigned($offset, 1),
            self::INT16 => $this->readSigned($offset, 2),
            self::INT32 => $this->readSigned($offset, 4),
            self::INT64 => $this->readSigned($offset, 8),
            self::INT128 => BigInt::fromBytesLE(
                $this->read($offset, 16),
                true,
            )->toIntOrString(),
            self::UINT8 => $this->readUnsigned($offset, 1),
            self::UINT16 => $this->readUnsigned($offset, 2),
            self::UINT32 => $this->readUnsigned($offset, 4),
            self::UINT64 => $this->readUnsigned($offset, 8),
            self::UINT128 => BigInt::fromBytesLE(
                $this->read($offset, 16),
                false,
            )->toIntOrString(),
            self::FLOAT => $this->readFloat($offset),
            self::DOUBLE => $this->readDouble($offset),
            self::DECIMAL => $this->readDecimal($offset),
            self::VARCHAR => $this->readStringData($offset),
            self::BLOB, self::GEOMETRY => $this->readStringData($offset),
            self::UUID => $this->readUuid($offset),
            self::DATE => Value::date(
                new DateValue($this->readSigned($offset, 4)),
            )->toPhp(),
            self::TIME_MICROS => Value::time(
                new TimeValue(TimeUnit::Micros, $this->readSigned($offset, 8)),
            )->toPhp(),
            self::TIME_NANOS => Value::time(
                new TimeValue(TimeUnit::Nanos, $this->readSigned($offset, 8)),
            )->toPhp(),
            self::TIME_MICROS_TZ => Value::timeTz(
                new TimeTzValue($this->readSigned($offset, 8)),
            )->toPhp(),
            self::TIMESTAMP_SEC => Value::timestamp(
                new TimestampValue(
                    TimestampUnit::Seconds,
                    $this->readSigned($offset, 8),
                ),
            )->toPhp(),
            self::TIMESTAMP_MILLIS => Value::timestamp(
                new TimestampValue(
                    TimestampUnit::Millis,
                    $this->readSigned($offset, 8),
                ),
            )->toPhp(),
            self::TIMESTAMP_MICROS => Value::timestamp(
                new TimestampValue(
                    TimestampUnit::Micros,
                    $this->readSigned($offset, 8),
                ),
            )->toPhp(),
            self::TIMESTAMP_NANOS => Value::timestamp(
                new TimestampValue(
                    TimestampUnit::Nanos,
                    $this->readSigned($offset, 8),
                ),
            )->toPhp(),
            self::TIMESTAMP_MICROS_TZ => Value::timestamp(
                new TimestampValue(
                    TimestampUnit::Micros,
                    $this->readSigned($offset, 8),
                    true,
                ),
            )->toPhp(),
            self::TIMESTAMP_NANOS_TZ => Value::timestamp(
                new TimestampValue(
                    TimestampUnit::Nanos,
                    $this->readSigned($offset, 8),
                    true,
                ),
            )->toPhp(),
            self::INTERVAL => Value::interval(
                new IntervalValue(
                    $this->readSigned($offset, 4),
                    $this->readSigned($offset + 4, 4),
                    $this->readSigned($offset + 8, 8),
                ),
            )->toPhp(),
            self::OBJECT => $this->decodeObject($offset),
            self::ARRAY => $this->decodeArray($offset),
            self::BIGNUM => Bignum::fromStorage($this->readStringData($offset)),
            self::BITSTRING => BitString::fromStorage($this->readStringData($offset)),
            default => throw new ProtocolException(
                "unsupported VARIANT type id {$typeId}",
            ),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeObject(int $offset): array
    {
        [$childCount, $cursor] = $this->readVarint($offset);
        $childrenIndex = 0;
        if ($childCount > 0) {
            [$childrenIndex] = $this->readVarint($cursor);
        }

        $result = [];
        for ($index = 0; $index < $childCount; $index++) {
            $child = $this->children[$childrenIndex + $index] ?? null;
            if ($child === null) {
                throw new ProtocolException('VARIANT object child is out of range');
            }
            $keyIndex = $child['keys_index'];
            if ($keyIndex === null) {
                throw new ProtocolException('VARIANT object child has no key');
            }
            $key = $this->keys[$keyIndex] ?? throw new ProtocolException(
                "VARIANT key index {$keyIndex} is out of range",
            );
            $result[$key] = $this->decodeValue($child['values_index']);
        }

        return $result;
    }

    /**
     * @return list<mixed>
     */
    private function decodeArray(int $offset): array
    {
        [$childCount, $cursor] = $this->readVarint($offset);
        $childrenIndex = 0;
        if ($childCount > 0) {
            [$childrenIndex] = $this->readVarint($cursor);
        }

        $result = [];
        for ($index = 0; $index < $childCount; $index++) {
            $child = $this->children[$childrenIndex + $index] ?? null;
            if ($child === null) {
                throw new ProtocolException('VARIANT array child is out of range');
            }
            $result[] = $this->decodeValue($child['values_index']);
        }

        return $result;
    }

    private function readDecimal(int $offset): float|string
    {
        [$width, $cursor] = $this->readVarint($offset);
        [$scale, $cursor] = $this->readVarint($cursor);
        $length = match (true) {
            $width <= 4 => 2,
            $width <= 9 => 4,
            $width <= 18 => 8,
            $width <= 38 => 16,
            default => throw new ProtocolException(
                "VARIANT DECIMAL width {$width} is unsupported",
            ),
        };
        $unscaled = BigInt::fromBytesLE(
            $this->read($cursor, $length),
            true,
        )->toIntOrString();

        return (new DecimalValue($unscaled, $width, $scale))->toPhp();
    }

    private function readUuid(int $offset): string
    {
        $bytes = $this->read($offset, 16);

        return (new HugeIntParts(
            BigInt::fromBytesLE(substr($bytes, 8, 8), true)->toInt(),
            BigInt::fromBytesLE(substr($bytes, 0, 8), false)->toIntOrString(),
        ))->uuid();
    }

    private function readFloat(int $offset): float
    {
        $unpacked = unpack('gvalue', $this->read($offset, 4));
        if ($unpacked === false || !is_float($unpacked['value'])) {
            throw new ProtocolException('cannot decode VARIANT FLOAT value');
        }

        return $unpacked['value'];
    }

    private function readDouble(int $offset): float
    {
        $unpacked = unpack('evalue', $this->read($offset, 8));
        if ($unpacked === false || !is_float($unpacked['value'])) {
            throw new ProtocolException('cannot decode VARIANT DOUBLE value');
        }

        return $unpacked['value'];
    }

    private function readStringData(int $offset): string
    {
        [$length, $cursor] = $this->readVarint($offset);

        return $this->read($cursor, $length);
    }

    private function readSigned(int $offset, int $length): int
    {
        return BigInt::fromBytesLE($this->read($offset, $length), true)->toInt();
    }

    private function readUnsigned(int $offset, int $length): int|string
    {
        return BigInt::fromBytesLE($this->read($offset, $length), false)->toIntOrString();
    }

    /**
     * @return array{0:int,1:int}
     */
    private function readVarint(int $offset): array
    {
        $result = 0;
        $shift = 0;
        while (true) {
            if ($offset >= strlen($this->data)) {
                throw new ProtocolException('VARIANT data is truncated');
            }
            $byte = ord($this->data[$offset]);
            $offset++;
            $result |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                return [$result, $offset];
            }
            $shift += 7;
            if ($shift >= 64) {
                throw new ProtocolException('VARIANT varint is too large');
            }
        }
    }

    private function read(int $offset, int $length): string
    {
        if ($offset < 0 || $length < 0 || $offset + $length > strlen($this->data)) {
            throw new ProtocolException('VARIANT data is truncated');
        }

        return substr($this->data, $offset, $length);
    }

    /**
     * @param array<string,Value> $fields
     * @return list<Value>
     */
    private static function requiredList(array $fields, string $name): array
    {
        $field = $fields[$name] ?? throw new ProtocolException(
            "VARIANT is missing the {$name} field",
        );
        if ($field->isNull()) {
            throw new ProtocolException("VARIANT {$name} field is NULL");
        }

        return $field->asList();
    }

    private static function optionalIndex(?Value $value): ?int
    {
        if ($value === null || $value->isNull()) {
            return null;
        }

        return self::requiredIndex($value, 'index');
    }

    private static function requiredIndex(?Value $value, string $name): int
    {
        if ($value === null || $value->isNull()) {
            throw new ProtocolException("VARIANT {$name} is missing");
        }
        $index = $value->asIntOrString();
        if (is_int($index)) {
            return $index;
        }
        if (preg_match('/^[0-9]+$/', $index) !== 1) {
            throw new ProtocolException("VARIANT {$name} is not a valid index");
        }
        $int = (int) $index;
        if ((string) $int !== $index) {
            throw new ProtocolException("VARIANT {$name} is out of range");
        }

        return $int;
    }
}
