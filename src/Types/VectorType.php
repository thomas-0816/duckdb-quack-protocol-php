<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;

enum VectorType: int
{
    case Flat = 0;
    case Constant = 2;
    case Dictionary = 3;
    case Sequence = 4;
    case Shredded = 5;

    public static function fromId(int $value): self
    {
        return self::tryFrom($value)
            ?? throw new ProtocolException("unknown vector type {$value}");
    }
}
