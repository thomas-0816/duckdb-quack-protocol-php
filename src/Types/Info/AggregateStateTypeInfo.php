<?php

declare(strict_types=1);

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;
use Quack\Protocol\Types\LogicalType;

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
