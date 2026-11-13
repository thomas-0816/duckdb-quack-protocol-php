<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtraTypeInfoType;

final class DecimalTypeInfo extends ExtraTypeInfo
{
    public function __construct(
        ?string $alias,
        public readonly int $width,
        public readonly int $scale,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Decimal;
    }
}
