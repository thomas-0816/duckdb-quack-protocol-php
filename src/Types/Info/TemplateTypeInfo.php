<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtraTypeInfoType;

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
