<?php

namespace DuckDB\Quack\Json;

final class JsonOptions
{
    public function __construct(
        public readonly BigIntJsonMode $bigint = BigIntJsonMode::String,
        public readonly BytesJsonMode $bytes = BytesJsonMode::Base64,
        public readonly TaggedJsonMode $decimal = TaggedJsonMode::Default,
        public readonly TaggedJsonMode $date = TaggedJsonMode::Default,
        public readonly TaggedJsonMode $time = TaggedJsonMode::Default,
        public readonly TaggedJsonMode $timestamp = TaggedJsonMode::Default,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }
}
