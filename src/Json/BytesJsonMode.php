<?php

declare(strict_types=1);

namespace Quack\Protocol\Json;

enum BytesJsonMode: string
{
    case Base64 = 'base64';
    case Hex = 'hex';
    case Array = 'array';
}
