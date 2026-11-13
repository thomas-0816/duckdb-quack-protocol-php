<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ChildType;
use DuckDB\Quack\Types\ExtraTypeInfoType;

final class StructTypeInfo extends ExtraTypeInfo
{
    /**
     * @param list<ChildType> $childTypes
     */
    public function __construct(
        ?string $alias,
        public readonly array $childTypes,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Struct;
    }
}
