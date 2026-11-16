<?php

namespace DuckDB\Quack\Types;

/**
 * Marks a shredded VARIANT field that is absent from the current object.
 *
 * A shredded object only stores the fields that are present, so the decoder
 * needs to distinguish "absent" from a stored NULL value.
 *
 * @internal
 */
enum VariantMissing
{
    case Value;
}
