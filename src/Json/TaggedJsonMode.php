<?php

declare(strict_types=1);

namespace Quack\Protocol\Json;

enum TaggedJsonMode: string
{
    case Default = 'default';
    case Tagged = 'tagged';
}
