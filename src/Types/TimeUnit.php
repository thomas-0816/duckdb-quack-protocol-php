<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

enum TimeUnit: string
{
    case Micros = 'micros';
    case Nanos = 'nanos';
}
