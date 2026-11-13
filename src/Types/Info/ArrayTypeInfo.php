<?php

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;
use Quack\Protocol\Types\LogicalType;

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
