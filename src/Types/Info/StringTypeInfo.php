<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtraTypeInfoType;

final class StringTypeInfo extends ExtraTypeInfo
{
    public function __construct(
        ?string $alias,
        public readonly string $collation,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::String;
    }
}
