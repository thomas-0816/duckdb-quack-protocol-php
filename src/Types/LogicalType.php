<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Types\Info\ExtraTypeInfo;

final class LogicalType
{
    public function __construct(
        public readonly LogicalTypeId $id,
        public readonly ?ExtraTypeInfo $typeInfo = null,
    ) {
    }

    public function withId(LogicalTypeId $id): self
    {
        return new self($id, $this->typeInfo);
    }

    public function alias(): ?string
    {
        return $this->typeInfo?->alias;
    }

    public function equals(self $other): bool
    {
        if ($this->id !== $other->id) {
            return false;
        }
        if ($this->typeInfo === null || $other->typeInfo === null) {
            return $this->typeInfo === $other->typeInfo;
        }
        return $this->typeInfo == $other->typeInfo;
    }
}
