<?php

declare(strict_types=1);

namespace Quack\Protocol\Json;

enum BigIntJsonMode: string
{
    case String = 'string';
    case Number = 'number';
}
