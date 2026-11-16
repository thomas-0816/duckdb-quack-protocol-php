<?php

namespace DuckDB\Quack\Sql;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Types\TemporalFormatter;
use DuckDB\Quack\Types\TimestampUnit;
use DuckDB\Quack\Types\TimeUnit;
use DuckDB\Quack\Types\Value;

/**
 * Formats SQL placeholders as DuckDB literals without touching literals,
 * quoted identifiers, or comments.
 */
final class SqlFormatter
{
    /**
     * @param SqlParameters|array<int|string,mixed>|null $params
     */
    public static function formatSql(string $sql, SqlParameters|array|null $params = null): string
    {
        $params = SqlParameters::coerce($params);
        if ($params === null) {
            return $sql;
        }
        if ($params->kind === SqlParameters::POSITIONAL) {
            return self::formatPositionalSql($sql, $params->positionalValues());
        }
        return self::formatNamedSql($sql, $params->namedValues());
    }

    public static function sqlLiteral(SqlParameter $value): string
    {
        if ($value->kind === SqlParameter::LIST) {
            return '[' . implode(', ', array_map(
                static fn (SqlParameter $item): string => self::sqlLiteral($item),
                $value->asList(),
            )) . ']';
        }
        return self::valueLiteral($value->asValue());
    }

    public static function valueLiteral(Value $value): string
    {
        return match ($value->kind) {
            Value::NULL => 'NULL',
            Value::BOOL => $value->asBool() ? 'TRUE' : 'FALSE',
            Value::INT, Value::UINT, Value::HUGEINT, Value::UHUGEINT => (string) $value->asIntOrString(),
            Value::FLOAT, Value::DOUBLE => self::floatLiteral($value->asFloat()),
            Value::STRING => "'" . str_replace("'", "''", $value->asString()) . "'",
            Value::BYTES => "from_hex('" . bin2hex($value->asBytes()) . "')",
            Value::DECIMAL => $value->asDecimal()->toString(),
            default => throw new ProtocolException("cannot format SQL parameter of kind {$value->kind}"),
        };
    }

    /**
     * @param list<SqlParameter> $params
     */
    private static function formatPositionalSql(string $sql, array $params): string
    {
        if ($params === [] && !str_contains($sql, '?')) {
            // Avoid scanning large statements (for example embedded JSON
            // documents) when there is nothing that could be replaced.
            return $sql;
        }
        $index = 0;
        $formatted = self::scanSql($sql, static function (string $token) use ($params, &$index): string {
            if ($token !== '?') {
                return $token;
            }
            $value = $params[$index] ?? null;
            if ($value === null) {
                throw new ProtocolException('SQL has more positional placeholders than parameters');
            }
            $index++;
            return self::sqlLiteral($value);
        });
        if ($index !== count($params)) {
            throw new ProtocolException("SQL has {$index} positional placeholders but " . count($params) . ' parameters were provided');
        }
        return $formatted;
    }

    /**
     * @param array<string,SqlParameter> $params
     */
    private static function formatNamedSql(string $sql, array $params): string
    {
        return self::scanSql($sql, static function (string $token) use ($params): string {
            if (!str_starts_with($token, ':')) {
                return $token;
            }
            $name = substr($token, 1);
            $value = $params[$name] ?? null;
            if ($value === null) {
                throw new ProtocolException("missing SQL parameter :{$name}");
            }
            return self::sqlLiteral($value);
        });
    }

    /**
     * Replaces tokens outside single quotes, double quotes, line comments, and
     * block comments.
     *
     * @param callable(string):string $replaceToken
     */
    public static function scanSql(string $sql, callable $replaceToken): string
    {
        $length = strlen($sql);
        $output = '';
        $index = 0;
        while ($index < $length) {
            // Copy runs of ordinary characters in one step; only the
            // characters below can start something that needs handling.
            $span = strcspn($sql, "'\"-/:?", $index);
            if ($span > 0) {
                $output .= substr($sql, $index, $span);
                $index += $span;
                if ($index >= $length) {
                    break;
                }
            }

            $ch = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : null;

            if ($ch === "'") {
                $end = self::readSingleQuoted($sql, $index);
                $output .= substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '"') {
                $end = self::readDoubleQuoted($sql, $index);
                $output .= substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '-' && $next === '-') {
                $newline = strpos($sql, "\n", $index + 2);
                $end = $newline === false ? $length : $newline;
                $output .= substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $close = strpos($sql, '*/', $index + 2);
                $end = $close === false ? $length : $close + 2;
                $output .= substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '?') {
                $output .= $replaceToken('?');
                $index++;
                continue;
            }
            if ($ch === ':'
                && ($index === 0 || $sql[$index - 1] !== ':')
                && $next !== ':'
                && $next !== null
                && self::isIdentifierStart($next)) {
                $start = $index + 1;
                $end = $start + 1;
                while ($end < $length && self::isIdentifierPart($sql[$end])) {
                    $end++;
                }
                $output .= $replaceToken(substr($sql, $index, $end - $index));
                $index = $end;
                continue;
            }
            $output .= $ch;
            $index++;
        }
        return $output;
    }

    private static function readSingleQuoted(string $sql, int $start): int
    {
        $length = strlen($sql);
        $index = $start + 1;
        while (($quote = strpos($sql, "'", $index)) !== false) {
            if ($quote + 1 < $length && $sql[$quote + 1] === "'") {
                $index = $quote + 2;
                continue;
            }
            return $quote + 1;
        }
        return $length;
    }

    private static function readDoubleQuoted(string $sql, int $start): int
    {
        $length = strlen($sql);
        $index = $start + 1;
        while (($quote = strpos($sql, '"', $index)) !== false) {
            if ($quote + 1 < $length && $sql[$quote + 1] === '"') {
                $index = $quote + 2;
                continue;
            }
            return $quote + 1;
        }
        return $length;
    }

    private static function isIdentifierStart(string $ch): bool
    {
        return $ch === '_' || ($ch >= 'a' && $ch <= 'z') || ($ch >= 'A' && $ch <= 'Z');
    }

    private static function isIdentifierPart(string $ch): bool
    {
        return self::isIdentifierStart($ch) || ($ch >= '0' && $ch <= '9');
    }

    private static function floatLiteral(float $value): string
    {
        if (!is_finite($value)) {
            throw new ProtocolException("cannot encode non-finite SQL number {$value}");
        }
        return (string) $value;
    }
}
