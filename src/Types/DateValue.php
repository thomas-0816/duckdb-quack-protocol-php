<?php

namespace DuckDB\Quack\Types;

final class DateValue
{
    public function __construct(public readonly int $days)
    {
    }
}
