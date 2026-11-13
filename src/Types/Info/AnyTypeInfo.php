<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtraTypeInfoType;
use DuckDB\Quack\Types\LogicalType;

final class AnyTypeInfo extends ExtraTypeInfo
{
    public function __construct(
        ?string $alias,
        public readonly LogicalType $targetType,
        public readonly int $castScore,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Any;
    }
}
