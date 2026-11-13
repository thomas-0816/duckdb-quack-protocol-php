<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtraTypeInfoType;
use DuckDB\Quack\Types\LogicalType;

final class ArrayTypeInfo extends ExtraTypeInfo
{
    public function __construct(
        ?string $alias,
        public readonly LogicalType $childType,
        public readonly int $size,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Array;
    }
}
