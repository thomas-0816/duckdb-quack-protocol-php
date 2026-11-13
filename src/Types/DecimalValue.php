<?php

namespace DuckDB\Quack\Types;

final class DecimalValue
{
    public function __construct(
        public readonly int|string $value,
        public readonly int $width,
        public readonly int $scale,
    ) {
    }

    /** Formats the unscaled value with the decimal point inserted. */
    public function toString(): string
    {
        $value = BigInt::coerce($this->value);
        $negative = $value->isNegative();
        $absolute = $value->abs();
        $sign = $negative ? '-' : '';
        if ($this->scale === 0) {
            return $sign . $absolute;
        }
        $factor = BigInt::fromString('1' . str_repeat('0', $this->scale));
        [$integer, $fraction] = $absolute->divMod($factor);
        $fractionText = str_pad((string) $fraction, $this->scale, '0', STR_PAD_LEFT);
        return $sign . $integer . '.' . $fractionText;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Returns a float when the unscaled value fits PHP's exact float range,
     * otherwise an exact decimal string.
     */
    public function toPhp(): float|string
    {
        if (BigInt::coerce($this->value)->abs()->compare(BigInt::fromInt(1 << 53)) <= 0) {
            return (float) $this->toString();
        }

        return $this->toString();
    }
}
