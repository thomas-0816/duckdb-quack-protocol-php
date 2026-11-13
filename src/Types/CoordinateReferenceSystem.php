<?php

namespace DuckDB\Quack\Types;

final class CoordinateReferenceSystem
{
    public function __construct(public readonly ?string $definition = null)
    {
    }

    public static function default(): self
    {
        return new self();
    }
}
