<?php

namespace DuckDB\Quack;

/**
 * A Quack protocol version this package speaks: v1 or v3.
 *
 * The number is private so no other version can be named.
 */
final class QuackVersion
{
    private static ?self $v1 = null;
    private static ?self $v3 = null;

    private function __construct(private readonly int $number)
    {
    }

    public static function v1(): self
    {
        return self::$v1 ??= new self(1);
    }

    public static function v3(): self
    {
        return self::$v3 ??= new self(3);
    }

    public static function fromNumber(int|string $number): ?self
    {
        return match ((int) $number) {
            1 => self::v1(),
            3 => self::v3(),
            default => null,
        };
    }

    public function number(): int
    {
        return $this->number;
    }

    public function equals(self $other): bool
    {
        return $this->number === $other->number;
    }

    public function __toString(): string
    {
        return (string) $this->number;
    }

    /** @return list<self> */
    public static function supported(): array
    {
        return [self::v3(), self::v1()];
    }
}
