<?php

namespace Quack\Protocol\Types;

final class TimeTzValue
{
    public function __construct(public readonly int $bits)
    {
    }
}
