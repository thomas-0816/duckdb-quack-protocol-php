<?php

namespace Quack\Protocol\Json;

enum BytesJsonMode: string
{
    case Base64 = 'base64';
    case Hex = 'hex';
    case Array = 'array';
}
