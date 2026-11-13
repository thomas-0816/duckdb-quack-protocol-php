<?php

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ChildType;
use Quack\Protocol\Types\ExtraTypeInfoType;

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
