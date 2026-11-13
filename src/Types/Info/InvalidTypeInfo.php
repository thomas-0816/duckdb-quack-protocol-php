<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtraTypeInfoType;

final class InvalidTypeInfo extends ExtraTypeInfo
{
    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Invalid;
    }
}
