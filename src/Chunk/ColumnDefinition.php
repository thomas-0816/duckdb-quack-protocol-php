<?php

namespace Quack\Protocol\Chunk;

use Quack\Protocol\Types\LogicalType;

final class ColumnDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly LogicalType $logicalType,
    ) {
    }
}
