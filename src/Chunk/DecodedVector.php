<?php

namespace Quack\Protocol\Chunk;

use Quack\Protocol\Types\LogicalType;
use Quack\Protocol\Types\Value;
use Quack\Protocol\Types\VectorType;

final class DecodedVector
{
    /**
     * @param list<Value> $values
     */
    public function __construct(
        public readonly LogicalType $logicalType,
        public readonly VectorType $vectorType,
        public readonly array $values,
    ) {
    }
}
