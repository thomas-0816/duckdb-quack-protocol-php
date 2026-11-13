<?php

namespace DuckDB\Quack\Types;

final class ChildType
{
    public function __construct(
        public readonly string $name,
        public readonly LogicalType $logicalType,
    ) {
    }
}
