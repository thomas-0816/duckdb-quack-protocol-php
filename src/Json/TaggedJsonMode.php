<?php

namespace DuckDB\Quack\Json;

enum TaggedJsonMode: string
{
    case Default = 'default';
    case Tagged = 'tagged';
}
