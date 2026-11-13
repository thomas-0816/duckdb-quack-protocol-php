<?php

declare(strict_types=1);

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\CoordinateReferenceSystem;
use Quack\Protocol\Types\ExtraTypeInfoType;

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
