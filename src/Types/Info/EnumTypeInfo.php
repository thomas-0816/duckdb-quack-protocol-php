<?php

declare(strict_types=1);

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;

final class EnumTypeInfo extends ExtraTypeInfo
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        ?string $alias,
        public readonly array $values,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Enum;
    }
}
