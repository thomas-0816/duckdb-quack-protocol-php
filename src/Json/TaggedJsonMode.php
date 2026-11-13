<?php

namespace Quack\Protocol\Json;

enum TaggedJsonMode: string
{
    case Default = 'default';
    case Tagged = 'tagged';
}
