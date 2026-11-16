<?php

namespace DuckDB\Quack\Types;

/**
 * Extension metadata attached to a logical type (DuckDB's field 103).
 */
final class ExtensionTypeInfo
{
    /**
     * @param list<LogicalTypeModifier> $modifiers
     * @param array<string,DuckDBValue> $properties
     */
    public function __construct(
        public readonly array $modifiers = [],
        public readonly array $properties = [],
    ) {
    }
}
