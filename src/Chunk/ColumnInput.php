<?php

namespace Quack\Protocol\Chunk;

use Quack\Protocol\Types\LogicalType;
use Quack\Protocol\Types\Value;

final class ColumnInput
{
    /**
     * @param list<Value> $values
     */
    public function __construct(
        public readonly ?string $name,
        public readonly LogicalType $logicalType,
        public readonly array $values,
    ) {
    }
}
