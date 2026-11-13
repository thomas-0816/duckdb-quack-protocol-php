<?php

namespace Quack\Protocol\Types;

enum TimeUnit: string
{
    case Micros = 'micros';
    case Nanos = 'nanos';
}
