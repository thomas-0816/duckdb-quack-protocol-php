<?php

namespace DuckDB\Quack\Types;

enum TimestampUnit: string
{
    case Seconds = 'seconds';
    case Millis = 'millis';
    case Micros = 'micros';
    case Nanos = 'nanos';
}
