<?php

namespace DuckDB\Quack\Types\Info;

use DuckDB\Quack\Types\ExtensionTypeInfo;
use DuckDB\Quack\Types\ExtraTypeInfoType;

/**
 * DuckDB's extra logical-type metadata. The concrete subclasses correspond to
 * the `ExtraTypeInfoType` wire variants.
 */
abstract class ExtraTypeInfo
{
    public ?ExtensionTypeInfo $extensionInfo = null;

    public function __construct(
        public readonly ?string $alias = null,
    ) {
    }

    abstract public function typeId(): ExtraTypeInfoType;
}
