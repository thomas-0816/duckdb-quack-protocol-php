<?php

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;

final class InvalidTypeInfo extends ExtraTypeInfo
{
    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Invalid;
    }
}
