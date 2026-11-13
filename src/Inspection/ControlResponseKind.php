<?php

declare(strict_types=1);

namespace Quack\Protocol\Inspection;

enum ControlResponseKind: string
{
    case Connected = 'connected';
    case Success = 'success';
    case Error = 'error';
}
