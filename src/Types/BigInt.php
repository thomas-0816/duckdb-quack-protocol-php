<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

use Quack\Protocol\Exceptions\ProtocolException;

/**
 * A minimal arbitrary-precision signed integer.
 *
 * PHP integers are signed 64-bit; the Quack protocol also carries unsigned
 * 64-bit, signed 128-bit, and unsigned 128-bit values, and DECIMAL unscaled
 * values that can exceed 64 bits. This class keeps those values exact by
 * storing a decimal magnitude plus a sign.
 */
final class BigInt
{
    private const U64_MAX = '18446744073709551615';
    private const I64_MAX = '9223372036854775807';
    private const I64_MIN = '-9223372036854775808';

    /** @var array<int,self> */
    private static array $pow2Cache = [];

    private function __construct(
        private string $digits,
        private bool $negative,
    ) {
        $this->digits = ltrim($digits, '0');
        if ($this->digits === '') {
            $this->digits = '0';
            $this->negative = false;
        }
    }

    public static function zero(): self
    {
        return new self('0', false);
    }

    public static function one(): self
    {
        return new self('1', false);
    }

    public static function fromInt(int $value): self
    {
        $text = (string) $value;
        if ($text[0] === '-') {
            return new self(substr($text, 1), true);
        }
        return new self($text, false);
    }

    public static function fromString(string $value): self
    {
        $text = trim($value);
        if ($text === '') {
            throw new ProtocolException('empty integer value');
        }
        $negative = false;
        if ($text[0] === '+' || $text[0] === '-') {
            $negative = $text[0] === '-';
            $text = substr($text, 1);
        }
        if ($text === '' || preg_match('/^[0-9]+$/', $text) !== 1) {
            throw new ProtocolException("invalid integer value {$value}");
        }
        return new self($text, $negative);
    }

    public static function coerce(self|int|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        return is_int($value) ? self::fromInt($value) : self::fromString($value);
    }

    public static function fromBytesLE(string $bytes, bool $signed): self
    {
        $length = strlen($bytes);
        if ($length === 0) {
            return self::zero();
        }
        $value = self::zero();
        for ($index = $length - 1; $index >= 0; $index--) {
            $value = $value->mulSmall(256)->addSmall(ord($bytes[$index]));
        }
        if ($signed && (ord($bytes[$length - 1]) & 0x80) !== 0) {
            $value = $value->sub(self::pow2($length * 8));
        }
        return $value;
    }

    public static function pow2(int $exponent): self
    {
        if ($exponent < 0) {
            throw new ProtocolException('negative power of two');
        }
        if (isset(self::$pow2Cache[$exponent])) {
            return self::$pow2Cache[$exponent];
        }
        $value = self::one();
        for ($index = 0; $index < $exponent; $index++) {
            $value = $value->add($value);
        }
        return self::$pow2Cache[$exponent] = $value;
    }

    public function digits(): string
    {
        return $this->digits;
    }

    public function isNegative(): bool
    {
        return $this->negative;
    }

    public function isZero(): bool
    {
        return $this->digits === '0';
    }

    public function isPositive(): bool
    {
        return !$this->negative && $this->digits !== '0';
    }

    public function abs(): self
    {
        return $this->negative ? new self($this->digits, false) : $this;
    }

    public function neg(): self
    {
        return $this->isZero() ? $this : new self($this->digits, !$this->negative);
    }

    public function add(self $other): self
    {
        if ($this->negative === $other->negative) {
            return new self(self::addDigits($this->digits, $other->digits), $this->negative);
        }
        $comparison = self::compareDigits($this->digits, $other->digits);
        if ($comparison === 0) {
            return self::zero();
        }
        if ($comparison > 0) {
            return new self(self::subDigits($this->digits, $other->digits), $this->negative);
        }
        return new self(self::subDigits($other->digits, $this->digits), $other->negative);
    }

    public function sub(self $other): self
    {
        return $this->add($other->neg());
    }

    public function mul(self $other): self
    {
        if ($this->isZero() || $other->isZero()) {
            return self::zero();
        }
        return new self(
            self::mulDigits($this->digits, $other->digits),
            $this->negative !== $other->negative,
        );
    }

    public function addSmall(int $value): self
    {
        return $this->add(self::fromInt($value));
    }

    public function mulSmall(int $value): self
    {
        return $this->mul(self::fromInt($value));
    }

    public function compare(self $other): int
    {
        if ($this->negative !== $other->negative) {
            return $this->negative ? -1 : 1;
        }
        $comparison = self::compareDigits($this->digits, $other->digits);
        return $this->negative ? -$comparison : $comparison;
    }

    /**
     * Truncating division: the quotient rounds toward zero and the remainder
     * has the sign of the dividend.
     *
     * @return array{0:self,1:self}
     */
    public function divMod(self $divisor): array
    {
        if ($divisor->isZero()) {
            throw new ProtocolException('division by zero');
        }
        [$quotient, $remainder] = self::divModDigits($this->digits, $divisor->digits);
        return [
            new self($quotient, $this->negative !== $divisor->negative),
            new self($remainder, $this->negative),
        ];
    }

    public function shiftLeft(int $bits): self
    {
        if ($bits < 0) {
            throw new ProtocolException('negative shift');
        }
        return $this->mul(self::pow2($bits));
    }

    public function shiftRightArithmetic(int $bits): self
    {
        if ($bits < 0) {
            throw new ProtocolException('negative shift');
        }
        if ($bits === 0 || $this->isZero()) {
            return $this;
        }
        [$quotient, $remainder] = $this->divMod(self::pow2($bits));
        if ($this->negative && !$remainder->isZero()) {
            $quotient = $quotient->sub(self::one());
        }
        return $quotient;
    }

    public function lowBits(int $bits): self
    {
        if ($bits < 0) {
            throw new ProtocolException('negative bit count');
        }
        $modulus = self::pow2($bits);
        [, $remainder] = $this->divMod($modulus);
        if ($remainder->negative) {
            $remainder = $remainder->add($modulus);
        }
        return $remainder;
    }

    public function toInt(): int
    {
        $text = $this->__toString();
        $int = (int) $text;
        if ((string) $int !== $text) {
            throw new ProtocolException("integer {$text} does not fit a PHP integer");
        }
        return $int;
    }

    public function toIntOrString(): int|string
    {
        $text = $this->__toString();
        $int = (int) $text;
        return (string) $int === $text ? $int : $text;
    }

    public function toSigned64(): int
    {
        if ($this->compare(self::fromString(self::I64_MIN)) < 0
            || $this->compare(self::fromString(self::I64_MAX)) > 0) {
            throw new ProtocolException('signed LEB128 value exceeds i64');
        }
        return $this->toInt();
    }

    public function toUnsigned64(): int|string
    {
        if ($this->negative || $this->compare(self::fromString(self::U64_MAX)) > 0) {
            throw new ProtocolException('unsigned LEB128 value exceeds u64');
        }
        return $this->toIntOrString();
    }

    public function toBytesLE(int $length, bool $signed): string
    {
        if ($length <= 0) {
            throw new ProtocolException('byte length must be positive');
        }
        $bits = $length * 8;
        $modulus = self::pow2($bits);
        if ($signed) {
            $half = self::pow2($bits - 1);
            if ($this->negative) {
                if ($this->compare($half->neg()) < 0) {
                    throw new ProtocolException("integer {$this} does not fit {$bits}-bit signed");
                }
            } elseif ($this->compare($half) >= 0) {
                throw new ProtocolException("integer {$this} does not fit {$bits}-bit signed");
            }
        } else {
            if ($this->negative) {
                throw new ProtocolException("integer {$this} does not fit {$bits}-bit unsigned");
            }
            if ($this->compare($modulus) >= 0) {
                throw new ProtocolException("integer {$this} does not fit {$bits}-bit unsigned");
            }
        }

        $value = $this->negative ? $this->add($modulus) : $this->abs();
        $bytes = '';
        for ($index = 0; $index < $length; $index++) {
            [$value, $remainder] = $value->divMod(self::fromInt(256));
            $bytes .= chr($remainder->toInt() & 0xff);
        }
        return $bytes;
    }

    public function __toString(): string
    {
        return ($this->negative ? '-' : '') . $this->digits;
    }

    private static function normalizeDigits(string $digits): string
    {
        $digits = ltrim($digits, '0');
        return $digits === '' ? '0' : $digits;
    }

    private static function compareDigits(string $left, string $right): int
    {
        $left = self::normalizeDigits($left);
        $right = self::normalizeDigits($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) < strlen($right) ? -1 : 1;
        }
        return strcmp($left, $right);
    }

    private static function addDigits(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $carry = 0;
        $result = '';
        while ($leftIndex >= 0 || $rightIndex >= 0 || $carry !== 0) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += ord($left[$leftIndex--]) - 48;
            }
            if ($rightIndex >= 0) {
                $sum += ord($right[$rightIndex--]) - 48;
            }
            $result .= chr((48 + ($sum % 10)) & 0xff);
            $carry = intdiv($sum, 10);
        }
        return strrev($result);
    }

    /** Subtracts $right from $left; $left must be greater than or equal to $right. */
    private static function subDigits(string $left, string $right): string
    {
        $leftIndex = strlen($left) - 1;
        $rightIndex = strlen($right) - 1;
        $borrow = 0;
        $result = '';
        while ($leftIndex >= 0) {
            $difference = (ord($left[$leftIndex--]) - 48) - $borrow;
            if ($rightIndex >= 0) {
                $difference -= ord($right[$rightIndex--]) - 48;
            }
            if ($difference < 0) {
                $difference += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result .= chr((48 + $difference) & 0xff);
        }
        return self::normalizeDigits(strrev($result));
    }

    private static function mulDigits(string $left, string $right): string
    {
        $leftLength = strlen($left);
        $rightLength = strlen($right);
        /** @var array<int,int> $accumulator */
        $accumulator = array_fill(0, $leftLength + $rightLength, 0);
        for ($leftIndex = $leftLength - 1; $leftIndex >= 0; $leftIndex--) {
            $leftDigit = ord($left[$leftIndex]) - 48;
            for ($rightIndex = $rightLength - 1; $rightIndex >= 0; $rightIndex--) {
                $position = $leftIndex + $rightIndex + 1;
                $product = $leftDigit * (ord($right[$rightIndex]) - 48) + $accumulator[$position];
                $accumulator[$position] = $product % 10;
                $accumulator[$position - 1] += intdiv($product, 10);
            }
        }
        $result = '';
        foreach ($accumulator as $digit) {
            $result .= chr((48 + $digit) & 0xff);
        }
        return self::normalizeDigits($result);
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function divModDigits(string $dividend, string $divisor): array
    {
        $divisor = self::normalizeDigits($divisor);
        $quotient = '';
        $remainder = '0';
        $length = strlen($dividend);
        for ($index = 0; $index < $length; $index++) {
            $remainder = self::normalizeDigits($remainder . $dividend[$index]);
            $digit = 0;
            while (self::compareDigits($remainder, $divisor) >= 0) {
                $remainder = self::subDigits($remainder, $divisor);
                $digit++;
            }
            $quotient .= chr((48 + $digit) & 0xff);
        }
        return [self::normalizeDigits($quotient), self::normalizeDigits($remainder)];
    }
}
