<?php

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;
use Quack\Protocol\Types\LogicalType;

final class ListTypeInfo extends ExtraTypeInfo
{
    public function __construct(
        ?string $alias,
        public readonly LogicalType $childType,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::List;
    }
}
