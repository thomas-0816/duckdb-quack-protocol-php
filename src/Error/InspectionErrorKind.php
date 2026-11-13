<?php

namespace DuckDB\Quack\Error;

enum InspectionErrorKind: string
{
    case Incomplete = 'incomplete';
    case Invalid = 'invalid';
    case TooLarge = 'too_large';
}
