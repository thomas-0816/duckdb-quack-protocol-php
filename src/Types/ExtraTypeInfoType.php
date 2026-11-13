<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

use Quack\Protocol\Exceptions\ProtocolException;

enum ExtraTypeInfoType: int
{
    case Invalid = 0;
    case Generic = 1;
    case Decimal = 2;
    case String = 3;
    case List = 4;
    case Struct = 5;
    case Enum = 6;
    case Unbound = 7;
    case AggregateState = 8;
    case Array = 9;
    case Any = 10;
    case IntegerLiteral = 11;
    case Template = 12;
    case Geo = 13;

    public static function fromId(int $value): self
    {
        return self::tryFrom($value)
            ?? throw new ProtocolException("unknown ExtraTypeInfoType {$value}");
    }
}
