<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

final class TimestampValue
{
    public function __construct(
        public readonly TimestampUnit $unit,
        public readonly int $value,
        public readonly bool $timezoneUtc = false,
    ) {
    }
}
