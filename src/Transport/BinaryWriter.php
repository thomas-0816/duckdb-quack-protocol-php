<?php

namespace Quack\Protocol\Transport;

use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Exceptions\Utf8Exception;
use Quack\Protocol\Types\BigInt;
use Quack\Protocol\Types\HugeIntParts;

/**
 * A little-endian binary writer using DuckDB's BinarySerializer field layout.
 */
final class BinaryWriter
{
    private string $buffer = '';

    public static function new(): self
    {
        return new self();
    }

    public function intoBytes(): string
    {
        return $this->buffer;
    }

    public function asSlice(): string
    {
        return $this->buffer;
    }

    public function writeObject(callable $write): void
    {
        $write($this);
        $this->writeFieldId(0xffff);
    }

    public function writeField(int $fieldId, callable $write): void
    {
        $this->writeFieldId($fieldId);
        $write($this);
    }

    public function writeFieldId(int $fieldId): void
    {
        $this->buffer .= pack('v', $fieldId & 0xffff);
    }

    public function writeByte(int $value): void
    {
        $this->buffer .= chr($value & 0xff);
    }

    public function writeBytes(string $value): void
    {
        $this->buffer .= $value;
    }

    public function writeBool(bool $value): void
    {
        $this->writeByte($value ? 1 : 0);
    }

    public function writeUleb(BigInt|int|string $value): void
    {
        $value = BigInt::coerce($value);
        if ($value->isNegative()) {
            throw new ProtocolException('unsigned LEB128 value is negative');
        }
        $divisor = BigInt::fromInt(128);
        do {
            [$quotient, $remainder] = $value->divMod($divisor);
            $byte = $remainder->toInt();
            if (!$quotient->isZero()) {
                $byte |= 0x80;
            }
            $this->writeByte($byte);
            $value = $quotient;
        } while (!$value->isZero());
    }

    public function writeSleb(BigInt|int|string $value): void
    {
        $value = BigInt::coerce($value);
        $zero = BigInt::zero();
        $negativeOne = BigInt::fromInt(-1);
        while (true) {
            $byte = $value->lowBits(7)->toInt();
            $value = $value->shiftRightArithmetic(7);
            $signBitSet = ($byte & 0x40) !== 0;
            $done = ($value->compare($zero) === 0 && !$signBitSet)
                || ($value->compare($negativeOne) === 0 && $signBitSet);
            if (!$done) {
                $byte |= 0x80;
            }
            $this->writeByte($byte);
            if ($done) {
                return;
            }
        }
    }

    public function writeString(string $value): void
    {
        if ($value !== '' && preg_match('//u', $value) !== 1) {
            throw new Utf8Exception('invalid UTF-8 string');
        }
        $this->writeStringBytes($value);
    }

    public function writeStringBytes(string $value): void
    {
        $this->writeUleb(strlen($value));
        $this->writeBytes($value);
    }

    public function writeBlob(string $value): void
    {
        $this->writeUleb(strlen($value));
        $this->writeBytes($value);
    }

    /**
     * @template T
     * @param array<int,T> $items
     * @param callable(self,T,int):void $writeElement
     */
    public function writeList(array $items, callable $writeElement): void
    {
        $this->writeUleb(count($items));
        foreach (array_values($items) as $index => $item) {
            $writeElement($this, $item, $index);
        }
    }

    public function writeNullable(mixed $value, callable $writeValue): void
    {
        if ($value === null) {
            $this->writeBool(false);
            return;
        }
        $this->writeBool(true);
        $writeValue($this, $value);
    }

    public function writeHugeIntParts(HugeIntParts $value): void
    {
        $this->writeSleb($value->upper);
        $this->writeUleb(BigInt::coerce($value->lower));
    }

    public function writeFixedI8(int $value): void
    {
        $this->writeByte($value);
    }

    public function writeFixedU8(int $value): void
    {
        $this->writeByte($value);
    }

    public function writeFixedI16(int $value): void
    {
        $this->buffer .= pack('v', $value & 0xffff);
    }

    public function writeFixedU16(int $value): void
    {
        $this->buffer .= pack('v', $value & 0xffff);
    }

    public function writeFixedI32(int $value): void
    {
        $this->buffer .= pack('V', $value & 0xffffffff);
    }

    public function writeFixedU32(int $value): void
    {
        $this->buffer .= pack('V', $value & 0xffffffff);
    }

    public function writeFixedI64(BigInt|int|string $value): void
    {
        $this->buffer .= BigInt::coerce($value)->lowBits(64)->toBytesLE(8, false);
    }

    public function writeFixedU64(BigInt|int|string $value): void
    {
        $this->buffer .= BigInt::coerce($value)->toBytesLE(8, false);
    }

    public function writeFixedF32(float $value): void
    {
        $this->buffer .= pack('g', $value);
    }

    public function writeFixedF64(float $value): void
    {
        $this->buffer .= pack('e', $value);
    }
}
