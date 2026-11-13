<?php

namespace Quack\Protocol\Transport;

use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Exceptions\Utf8Exception;
use Quack\Protocol\Types\BigInt;
use Quack\Protocol\Types\HugeIntParts;

/**
 * A bounded little-endian binary reader. Reading past the end marks the reader
 * as incomplete, which inspection helpers distinguish from malformed input.
 */
final class BinaryReader
{
    private int $offset = 0;
    private bool $incomplete = false;

    public function __construct(private readonly string $bytes)
    {
    }

    public function isIncomplete(): bool
    {
        return $this->incomplete;
    }

    public function remaining(): int
    {
        return max(0, strlen($this->bytes) - $this->offset);
    }

    public function eof(): bool
    {
        return $this->remaining() === 0;
    }

    public function assertEof(): void
    {
        if (!$this->eof()) {
            throw new ProtocolException("unexpected trailing bytes at offset {$this->offset}");
        }
    }

    /**
     * @template T
     * @param callable(self):T $read
     * @return T
     */
    public function readObject(callable $read): mixed
    {
        $result = $read($this);
        $this->readEndObject();
        return $result;
    }

    public function readEndObject(): void
    {
        $fieldId = $this->readFieldId();
        if ($fieldId !== 0xffff) {
            throw new ProtocolException(
                'expected end of object at offset ' . ($this->offset - 2)
                . ", got field {$fieldId}",
            );
        }
    }

    public function readFieldId(): int
    {
        return $this->unpackInt('v', 2);
    }

    public function peekFieldId(): int
    {
        $offset = $this->offset;
        $value = $this->unpackInt('v', 2);
        $this->offset = $offset;
        return $value;
    }

    /**
     * @template T
     * @param callable(self):T $read
     * @return T
     */
    public function readRequiredField(int $fieldId, callable $read): mixed
    {
        $actual = $this->readFieldId();
        if ($actual !== $fieldId) {
            throw new ProtocolException(
                "expected field {$fieldId} at offset " . ($this->offset - 2)
                . ", got {$actual}",
            );
        }
        return $read($this);
    }

    /**
     * @template T
     * @param callable(self):T $read
     * @param T $defaultValue
     * @return T
     */
    public function readOptionalField(int $fieldId, callable $read, mixed $defaultValue): mixed
    {
        if ($this->peekFieldId() !== $fieldId) {
            return $defaultValue;
        }
        $this->readFieldId();
        return $read($this);
    }

    public function readByte(): int
    {
        $this->ensure(1);
        return ord($this->bytes[$this->offset++]);
    }

    public function readBytes(int $length): string
    {
        $this->ensure($length);
        $value = substr($this->bytes, $this->offset, $length);
        $this->offset += $length;
        return $value;
    }

    public function readBool(): bool
    {
        $value = $this->readByte();
        return match ($value) {
            0 => false,
            1 => true,
            default => throw new ProtocolException("invalid boolean byte {$value}"),
        };
    }

    public function readUleb(): BigInt
    {
        $result = BigInt::zero();
        $shift = 0;
        for ($index = 0; $index < 19; $index++) {
            $byte = $this->readByte();
            $result = $result->add(BigInt::fromInt($byte & 0x7f)->shiftLeft($shift));
            if (($byte & 0x80) === 0) {
                return $result;
            }
            $shift += 7;
        }
        throw new ProtocolException('unsigned LEB128 value is too long');
    }

    public function readUlebU64(): int|string
    {
        return $this->readUleb()->toUnsigned64();
    }

    public function readUlebInt(): int
    {
        try {
            return $this->readUleb()->toInt();
        } catch (ProtocolException) {
            throw new ProtocolException('unsigned LEB128 value exceeds int');
        }
    }

    public function readUlebUsize(): int
    {
        return $this->readUlebInt();
    }

    public function readSleb(): BigInt
    {
        $result = BigInt::zero();
        $shift = 0;
        for ($index = 0; $index < 19; $index++) {
            $byte = $this->readByte();
            $result = $result->add(BigInt::fromInt($byte & 0x7f)->shiftLeft($shift));
            $shift += 7;
            if (($byte & 0x80) === 0) {
                if (($byte & 0x40) !== 0) {
                    $result = $result->sub(BigInt::pow2($shift));
                }
                return $result;
            }
        }
        throw new ProtocolException('signed LEB128 value is too long');
    }

    public function readSlebI64(): int
    {
        return $this->readSleb()->toSigned64();
    }

    public function readString(): string
    {
        $bytes = $this->readStringBytes();
        if ($bytes !== '' && preg_match('//u', $bytes) !== 1) {
            throw new Utf8Exception('invalid UTF-8 string');
        }
        return $bytes;
    }

    public function readStringBytes(): string
    {
        return $this->readBytes($this->readUlebUsize());
    }

    public function readBlob(): string
    {
        return $this->readBytes($this->readUlebUsize());
    }

    /**
     * @template T
     * @param callable(self,int):T $readElement
     * @return list<T>
     */
    public function readList(callable $readElement): array
    {
        $length = $this->readUlebUsize();
        $result = [];
        for ($index = 0; $index < $length; $index++) {
            $result[] = $readElement($this, $index);
        }
        return $result;
    }

    /**
     * @template T
     * @param callable(self):T $readValue
     * @return T|null
     */
    public function readNullable(callable $readValue): mixed
    {
        return $this->readBool() ? $readValue($this) : null;
    }

    public function readHugeIntParts(): HugeIntParts
    {
        return new HugeIntParts($this->readSlebI64(), $this->readUlebU64());
    }

    public function readFixedI8(): int
    {
        $value = $this->readByte();
        return $value >= 0x80 ? $value - 0x100 : $value;
    }

    public function readFixedU8(): int
    {
        return $this->readByte();
    }

    public function readFixedI16(): int
    {
        $value = $this->unpackInt('v', 2);
        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function readFixedU16(): int
    {
        return $this->unpackInt('v', 2);
    }

    public function readFixedI32(): int
    {
        $value = $this->unpackInt('V', 4);
        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function readFixedU32(): int
    {
        return $this->unpackInt('V', 4);
    }

    public function readFixedI64(): int
    {
        return BigInt::fromBytesLE($this->readBytes(8), true)->toInt();
    }

    public function readFixedU64(): int|string
    {
        return BigInt::fromBytesLE($this->readBytes(8), false)->toIntOrString();
    }

    public function readFixedF32(): float
    {
        return $this->unpackFloat('g', 4);
    }

    public function readFixedF64(): float
    {
        return $this->unpackFloat('e', 8);
    }

    private function unpackInt(string $format, int $length): int
    {
        $result = unpack($format, $this->readBytes($length));
        if ($result === false || !is_int($result[1] ?? null)) {
            throw new ProtocolException("cannot unpack {$format} value");
        }
        return $result[1];
    }

    private function unpackFloat(string $format, int $length): float
    {
        $result = unpack($format, $this->readBytes($length));
        if ($result === false || !is_float($result[1] ?? null)) {
            throw new ProtocolException("cannot unpack {$format} value");
        }
        return $result[1];
    }

    private function ensure(int $length): void
    {
        if ($length > $this->remaining()) {
            $this->incomplete = true;
            throw new ProtocolException(
                "unexpected end of input at offset {$this->offset}; needed {$length} byte(s), have {$this->remaining()}",
            );
        }
    }
}
