<?php

namespace Quack\Protocol\Types\Info;

use Quack\Protocol\Types\ExtraTypeInfoType;

/**
 * DuckDB's extra logical-type metadata. The concrete subclasses correspond to
 * the `ExtraTypeInfoType` wire variants.
 */
abstract class ExtraTypeInfo
{
    public function __construct(public readonly ?string $alias = null)
    {
    }

    abstract public function typeId(): ExtraTypeInfoType;
}
