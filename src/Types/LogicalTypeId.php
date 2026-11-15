<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;

enum LogicalTypeId: int
{
    case Invalid = 0;
    case SqlNull = 1;
    case Unknown = 2;
    case Any = 3;
    case Unbound = 4;
    case Template = 5;
    case Type = 6;
    case Boolean = 10;
    case TinyInt = 11;
    case SmallInt = 12;
    case Integer = 13;
    case BigInt = 14;
    case Date = 15;
    case Time = 16;
    case TimestampSec = 17;
    case TimestampMs = 18;
    case Timestamp = 19;
    case TimestampNs = 20;
    case Decimal = 21;
    case Float = 22;
    case Double = 23;
    case Char = 24;
    case Varchar = 25;
    case Blob = 26;
    case Interval = 27;
    case UTinyInt = 28;
    case USmallInt = 29;
    case UInteger = 30;
    case UBigInt = 31;
    case TimestampTz = 32;
    case TimestampTzNs = 33;
    case TimeTz = 34;
    case TimeNs = 35;
    case Bit = 36;
    case StringLiteral = 37;
    case IntegerLiteral = 38;
    case BigNum = 39;
    case UHugeInt = 49;
    case HugeInt = 50;
    case Pointer = 51;
    case Validity = 53;
    case Uuid = 54;
    case Geometry = 60;
    case Struct = 100;
    case List = 101;
    case Map = 102;
    case Table = 103;
    case Enum = 104;
    case AggregateState = 105;
    case Lambda = 106;
    case Union = 107;
    case Array = 108;
    case Variant = 109;
    case Tuple = 110;

    public static function fromId(int $value): self
    {
        return self::tryFrom($value)
            ?? throw new ProtocolException("unknown LogicalTypeId {$value}");
    }
}
