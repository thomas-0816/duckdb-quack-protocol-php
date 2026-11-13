<?php

namespace DuckDB\Quack\Types;

final class TimeValue
{
    public function __construct(
        public readonly TimeUnit $unit,
        public readonly int $value,
    ) {
    }
}
