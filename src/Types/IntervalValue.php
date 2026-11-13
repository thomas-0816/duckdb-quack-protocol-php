<?php

namespace Quack\Protocol\Types;

final class IntervalValue
{
    public function __construct(
        public readonly int $months,
        public readonly int $days,
        public readonly int $micros,
    ) {
    }
}
