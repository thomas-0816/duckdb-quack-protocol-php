<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

use Quack\Protocol\Exceptions\ProtocolException;

enum PhysicalType: int
{
    case Bool = 1;
    case UInt8 = 2;
    case Int8 = 3;
    case UInt16 = 4;
    case Int16 = 5;
    case UInt32 = 6;
    case Int32 = 7;
    case UInt64 = 8;
    case Int64 = 9;
    case Float = 11;
    case Double = 12;
    case Interval = 21;
    case List = 23;
    case Struct = 24;
    case Array = 29;
    case Varchar = 200;
    case UInt128 = 203;
    case Int128 = 204;
    case Bit = 206;
    case Invalid = 255;

    public static function fromId(int $value): self
    {
        return self::tryFrom($value)
            ?? throw new ProtocolException("unknown physical type {$value}");
    }
}
