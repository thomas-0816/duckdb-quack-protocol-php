<?php

declare(strict_types=1);

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;

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
