<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

use Quack\Protocol\Exceptions\ProtocolException;

enum VectorType: int
{
    case Flat = 0;
    case Fsst = 1;
    case Constant = 2;
    case Dictionary = 3;
    case Sequence = 4;

    public static function fromId(int $value): self
    {
        return self::tryFrom($value)
            ?? throw new ProtocolException("unknown vector type {$value}");
    }
}
