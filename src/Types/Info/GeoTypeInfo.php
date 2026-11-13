<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\CoordinateReferenceSystem;
use DuckDB\Quack\Types\ExtraTypeInfoType;

final class GeoTypeInfo extends ExtraTypeInfo
{
    public function __construct(
        ?string $alias,
        public readonly CoordinateReferenceSystem $crs,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Geo;
    }
}
