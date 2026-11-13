<?php

declare(strict_types=1);

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;
use Quack\Protocol\Types\LogicalType;

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
