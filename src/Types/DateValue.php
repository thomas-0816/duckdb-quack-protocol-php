<?php

declare(strict_types=1);

namespace Quack\Protocol\Types;

final class DateValue
{
    public function __construct(public readonly int $days)
    {
    }
}
