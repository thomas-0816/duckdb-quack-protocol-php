<?php

declare(strict_types=1);

namespace Quack\Protocol\Error;

enum InspectionErrorKind: string
{
    case Incomplete = 'incomplete';
    case Invalid = 'invalid';
    case TooLarge = 'too_large';
}
