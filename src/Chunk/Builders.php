<?php

namespace Quack\Protocol\Chunk;

use Quack\Protocol\Exceptions\ProtocolException;
use Quack\Protocol\Types\ChildType;
use Quack\Protocol\Types\LogicalType;
use Quack\Protocol\Types\LogicalTypeCodec;
use Quack\Protocol\Types\LogicalTypeId;
use Quack\Protocol\Types\LogicalTypes;
use Quack\Protocol\Types\TimestampUnit;
use Quack\Protocol\Types\TimeUnit;
use Quack\Protocol\Types\Value;
use Quack\Protocol\Types\VectorType;

/**
 * Builds DataChunks from columns, row maps, and inferred types.
 */
final class Builders
{
    /**
     * @param list<Value> $values
     */
    public static function column(
        LogicalType $logicalType,
        array $values,
        ?string $name = null,
    ): ColumnInput {
        return new ColumnInput($name, $logicalType, $values);
    }

    /**
     * @param list<ColumnInput> $columns
     */
    public static function dataChunk(array $columns): DataChunk
    {
        if ($columns === []) {
            throw new ProtocolException('a Quack DataChunk must contain at least one column');
        }
        $rowCount = count($columns[0]->values);
        $types = [];
        $decodedColumns = [];
        $names = [];

        foreach ($columns as $index => $input) {
            if (count($input->values) !== $rowCount) {
                throw new ProtocolException(
                    "column {$index} has " . count($input->values)
                    . " values, expected {$rowCount}",
                );
            }
            $names[] = $input->name ?? "column{$index}";
            $types[] = $input->logicalType;
            $decodedColumns[] = new DecodedVector(
                $input->logicalType,
                VectorType::Flat,
                $input->values,
            );
        }

        return new DataChunk($rowCount, $types, $decodedColumns, $names);
    }

    /**
     * @param list<array<string,Value>> $rows
     * @param list<ColumnDefinition>|null $columns
     */
    public static function dataChunkFromRows(array $rows, ?array $columns = null): DataChunk
    {
        $definitions = $columns ?? self::inferColumnDefinitions($rows);
        $inputs = [];
        foreach ($definitions as $definition) {
            $values = [];
            foreach ($rows as $row) {
                $values[] = self::normalizeAppendValue(
                    $row[$definition->name] ?? Value::null(),
                    $definition->logicalType,
                );
            }
            $inputs[] = new ColumnInput($definition->name, $definition->logicalType, $values);
        }
        return self::dataChunk($inputs);
    }

    /**
     * @param list<array<string,Value>> $rows
     * @return list<ColumnDefinition>
     */
    private static function inferColumnDefinitions(array $rows): array
    {
        $first = $rows[0] ?? throw new ProtocolException(
            'cannot infer append row columns from an empty row set',
        );
        $definitions = [];
        foreach (array_keys($first) as $name) {
            $values = [];
            foreach ($rows as $row) {
                $values[] = $row[$name] ?? Value::null();
            }
            $definitions[] = new ColumnDefinition($name, self::inferLogicalType($values));
        }
        return $definitions;
    }

    /**
     * @param list<Value> $values
     */
    public static function inferLogicalType(array $values): LogicalType
    {
        $value = null;
        foreach ($values as $candidate) {
            if (!$candidate->isNull()) {
                $value = $candidate;
                break;
            }
        }
        if ($value === null) {
            throw new ProtocolException('cannot infer logical type from only null values');
        }

        return match ($value->kind) {
            Value::BOOL => LogicalTypes::boolean(),
            Value::INT => self::inferIntType($value->asInt()),
            Value::UINT => self::inferUIntType($value->asIntOrString()),
            Value::HUGEINT => LogicalTypes::hugeint(),
            Value::UHUGEINT => LogicalTypes::uhugeint(),
            Value::FLOAT => LogicalTypes::float(),
            Value::DOUBLE => LogicalTypes::double(),
            Value::STRING => LogicalTypes::varchar(),
            Value::BYTES => LogicalTypes::blob(),
            Value::DECIMAL => LogicalTypes::decimal(
                $value->asDecimal()->width,
                $value->asDecimal()->scale,
            ),
            Value::DATE => LogicalTypes::date(),
            Value::TIME => $value->asTime()->unit === TimeUnit::Micros
                ? LogicalTypes::time()
                : LogicalTypes::timeNs(),
            Value::TIME_TZ => LogicalTypes::timeTz(),
            Value::TIMESTAMP => self::inferTimestampType($value->asTimestamp()),
            Value::INTERVAL => LogicalTypes::interval(),
            Value::LIST => LogicalTypes::list(self::inferLogicalType($value->asList())),
            Value::STRUCT => self::inferStructType($values, $value->asStruct()),
            default => throw new ProtocolException(
                "cannot infer logical type for value kind {$value->kind}",
            ),
        };
    }

    private static function inferIntType(int $value): LogicalType
    {
        if ($value >= -2147483648 && $value <= 2147483647) {
            return LogicalTypes::integer();
        }
        return LogicalTypes::bigint();
    }

    private static function inferUIntType(int|string $value): LogicalType
    {
        if (is_int($value) && $value <= 4294967295) {
            return LogicalTypes::uinteger();
        }
        return LogicalTypes::ubigint();
    }

    private static function inferTimestampType(\Quack\Protocol\Types\TimestampValue $value): LogicalType
    {
        return match ($value->unit) {
            TimestampUnit::Seconds => LogicalTypes::timestampSeconds(),
            TimestampUnit::Millis => LogicalTypes::timestampMillis(),
            TimestampUnit::Micros => $value->timezoneUtc
                ? LogicalTypes::timestampTz()
                : LogicalTypes::timestamp(),
            TimestampUnit::Nanos => LogicalTypes::timestampNanos(),
        };
    }

    /**
     * @param list<Value> $values
     * @param array<string,Value> $firstRow
     */
    private static function inferStructType(array $values, array $firstRow): LogicalType
    {
        $children = [];
        foreach (array_keys($firstRow) as $name) {
            $childValues = [];
            foreach ($values as $value) {
                $childValues[] = $value->kind === Value::STRUCT
                    ? ($value->asStruct()[$name] ?? Value::null())
                    : Value::null();
            }
            $children[] = new ChildType($name, self::inferLogicalType($childValues));
        }
        return LogicalTypes::struct($children);
    }

    private static function normalizeAppendValue(Value $value, LogicalType $logicalType): Value
    {
        if ($value->isNull()) {
            return Value::null();
        }
        if ($value->kind === Value::LIST && in_array($logicalType->id, [
            LogicalTypeId::List,
            LogicalTypeId::Map,
            LogicalTypeId::Array,
        ], true)) {
            $childType = LogicalTypeCodec::getChildType($logicalType);
            $normalized = [];
            foreach ($value->asList() as $item) {
                $normalized[] = self::normalizeAppendValue($item, $childType);
            }
            return Value::list($normalized);
        }
        if ($value->kind === Value::STRUCT && $logicalType->id === LogicalTypeId::Struct) {
            $children = LogicalTypeCodec::getStructChildren($logicalType);
            $normalized = [];
            foreach ($children as $child) {
                $normalized[$child->name] = self::normalizeAppendValue(
                    $value->asStruct()[$child->name] ?? Value::null(),
                    $child->logicalType,
                );
            }
            return Value::struct($normalized);
        }
        return $value;
    }
}
