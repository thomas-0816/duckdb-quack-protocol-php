<?php

namespace DuckDB\Quack\Types;

/**
 * A labelled extension type modifier (DuckDB's `LogicalTypeModifier`).
 */
final class LogicalTypeModifier
{
    public function __construct(
        public readonly SerializedValue $value,
        public readonly string $label = '',
    ) {
    }
}
