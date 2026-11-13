<?php

namespace Quack\Protocol\Types;

final class DateValue
{
    public function __construct(public readonly int $days)
    {
    }
}
