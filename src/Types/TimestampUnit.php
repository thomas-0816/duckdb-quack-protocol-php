<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

enum TimestampUnit: string
{
    case Seconds = 'seconds';
    case Millis = 'millis';
    case Micros = 'micros';
    case Nanos = 'nanos';
}
