<?php

namespace DuckDB\Quack\Types;

enum TimeUnit: string
{
    case Micros = 'micros';
    case Nanos = 'nanos';
}
