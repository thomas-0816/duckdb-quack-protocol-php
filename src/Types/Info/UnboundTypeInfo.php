<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtraTypeInfoType;

final class UnboundTypeInfo extends ExtraTypeInfo
{
    public function __construct(
        ?string $alias,
        public readonly ?string $name = null,
        public readonly ?string $catalog = null,
        public readonly ?string $schema = null,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::Unbound;
    }
}
