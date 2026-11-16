<?php

namespace DuckDB\Quack\Types;

use DuckDB\Quack\Exceptions\ProtocolException;

/**
 * A DuckDB `Value` as serialized by `Value::Serialize`.
 *
 * Unlike {@see Value}, which models values read from a data chunk, this value
 * carries its own logical type and is used by metadata that embeds DuckDB
 * values (extension type info and integer-literal type info).
 */
final class SerializedValue
{
    public function __construct(
        public readonly LogicalType $logicalType,
        public readonly bool $isNull = false,
        public readonly mixed $value = null,
    ) {
    }

    public static function null(LogicalType $logicalType): self
    {
        return new self($logicalType, true);
    }

    /**
     * Converts the value to native PHP.
     *
     * Nested lists become lists, structs become associative arrays, ENUM
     * indices become their labels, and TYPE values become a {@see LogicalType}.
     */
    public function toPhp(): mixed
    {
        if ($this->isNull) {
            return null;
        }
        if ($this->logicalType->id === LogicalTypeId::Type) {
            return $this->value;
        }
        if ($this->logicalType->id === LogicalTypeId::Enum) {
            return $this->enumToPhp();
        }

        return match (LogicalTypeCodec::getPhysicalType($this->logicalType)) {
            PhysicalType::List, PhysicalType::Array => $this->listToPhp(),
            PhysicalType::Struct => $this->structToPhp(),
            default => $this->value,
        };
    }

    private function enumToPhp(): string
    {
        $index = $this->value;
        if (!is_int($index) && !is_string($index)) {
            throw new ProtocolException('serialized DuckDB ENUM value is not an index');
        }
        $values = LogicalTypeCodec::getEnumValues($this->logicalType);
        $position = (int) $index;
        if ((string) $position !== (string) $index || !isset($values[$position])) {
            throw new ProtocolException("ENUM index {$index} is out of range");
        }

        return $values[$position];
    }

    /**
     * @return list<mixed>
     */
    private function listToPhp(): array
    {
        if (!is_array($this->value)) {
            throw new ProtocolException('serialized DuckDB list value is not an array');
        }
        $result = [];
        foreach ($this->value as $item) {
            if (!$item instanceof self) {
                throw new ProtocolException('serialized DuckDB list contains a non-value element');
            }
            $result[] = $item->toPhp();
        }

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function structToPhp(): array
    {
        if (!is_array($this->value)) {
            throw new ProtocolException('serialized DuckDB struct value is not an array');
        }
        $result = [];
        foreach ($this->value as $name => $item) {
            if (!is_string($name) || !$item instanceof self) {
                throw new ProtocolException('serialized DuckDB struct contains an invalid field');
            }
            $result[$name] = $item->toPhp();
        }

        return $result;
    }
}
