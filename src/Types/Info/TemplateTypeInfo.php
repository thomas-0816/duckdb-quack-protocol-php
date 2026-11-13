<?php

declare(strict_types=1);

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;

final class TemplateTypeInfo extends ExtraTypeInfo
{
    public function __construct(
        ?string $alias,
        public readonly string $name,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Template;
    }
}
