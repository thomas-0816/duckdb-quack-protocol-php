<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

final class TimeValue
{
    public function __construct(
        public readonly TimeUnit $unit,
        public readonly int $value,
    ) {
    }
}
