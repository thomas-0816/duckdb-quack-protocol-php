<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtraTypeInfoType;
use DuckDB\Quack\Types\LogicalType;

final class AggregateStateTypeInfo extends ExtraTypeInfo
{
    /**
     * @param list<LogicalType> $boundArgumentTypes
     */
    public function __construct(
        ?string $alias,
        public readonly string $functionName,
        public readonly LogicalType $returnType,
        public readonly array $boundArgumentTypes,
    ) {
        parent::__construct($alias);
    }

    public function typeId(): ExtraTypeInfoType
    {
        return ExtraTypeInfoType::AggregateState;
    }
}
