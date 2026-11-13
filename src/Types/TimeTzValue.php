<?php

namespace DuckDB\Quack\Types;

final class TimeTzValue
{
    public function __construct(public readonly int $bits)
    {
    }
}
