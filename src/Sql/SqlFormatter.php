<?php

namespace DuckDB\Quack\Sql;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Types\Value;

/**
 * Formats SQL placeholders as DuckDB literals without touching literals,
 * quoted identifiers, or comments.
 */
final class SqlFormatter
{
    /**
     * Formats positional (`?`) or named (`:name`) placeholders. The form is
     * detected from the array keys: a list is positional, anything else is
     * named.
     *
     * @param array<int|string,mixed>|null $params
     */
    public static function formatSql(string $sql, ?array $params = null): string
    {
        if ($params === null) {
            return $sql;
        }

        if (array_is_list($params)) {
            $positional = [];
            foreach ($params as $value) {
                $positional[] = SqlParameter::fromPhp($value);
            }

            return self::formatPositionalSql($sql, $positional);
        }

        $named = [];
        foreach ($params as $name => $value) {
            if (!is_string($name)) {
                throw new ProtocolException('named SQL parameter keys must be strings');
            }
            $named[$name] = SqlParameter::fromPhp($value);
        }

        return self::formatNamedSql($sql, $named);
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
        $formatted = implode('', self::scanSqlParts($sql, static function (string $token) use ($params, &$index): string|SqlParameter {
            if ($token !== '?') {
                return $token;
            }
            $value = $params[$index] ?? null;
            if ($value === null) {
                throw new ProtocolException('SQL has more positional placeholders than parameters');
            }
            $index++;
            return $value;
        }));
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
        if ($params === [] && !str_contains($sql, ':')) {
            // Avoid scanning large statements (for example embedded JSON
            // documents) when there is nothing that could be replaced.
            return $sql;
        }
        return implode('', self::scanSqlParts($sql, static function (string $token) use ($params): string|SqlParameter {
            if (!str_starts_with($token, ':')) {
                return $token;
            }
            $name = substr($token, 1);
            $value = $params[$name] ?? null;
            if ($value === null) {
                throw new ProtocolException("missing SQL parameter :{$name}");
            }
            return $value;
        }));
    }

    /**
     * Replaces tokens outside single quotes, double quotes, line comments, and
     * block comments.
     *
     * @param callable(string):string $replaceToken
     */
    public static function scanSql(string $sql, callable $replaceToken): string
    {
        return implode('', self::scanSqlParts($sql, static fn (string $token): string => $replaceToken($token)));
    }

    /**
     * The scan behind {@see self::scanSql()}. A replacement may be a parameter
     * literal instead of a string, so it can be appended in pieces and joined
     * into the statement once instead of being built into an intermediate
     * string first.
     *
     * @param callable(string):(string|SqlParameter) $replaceToken
     * @return list<string>
     */
    private static function scanSqlParts(string $sql, callable $replaceToken): array
    {
        $length = strlen($sql);
        $parts = [];
        $index = 0;
        while ($index < $length) {
            // Copy runs of ordinary characters in one step; only the
            // characters below can start something that needs handling.
            $span = strcspn($sql, "'\"-/:?", $index);
            if ($span > 0) {
                $parts[] = substr($sql, $index, $span);
                $index += $span;
                if ($index >= $length) {
                    break;
                }
            }

            $ch = $sql[$index];
            $next = $index + 1 < $length ? $sql[$index + 1] : null;

            if ($ch === "'") {
                $end = self::readSingleQuoted($sql, $index);
                $parts[] = substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '"') {
                $end = self::readDoubleQuoted($sql, $index);
                $parts[] = substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '-' && $next === '-') {
                $newline = strpos($sql, "\n", $index + 2);
                $end = $newline === false ? $length : $newline;
                $parts[] = substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $close = strpos($sql, '*/', $index + 2);
                $end = $close === false ? $length : $close + 2;
                $parts[] = substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '?') {
                $token = '?';
                $index++;
            } elseif ($ch === ':'
                && ($index === 0 || $sql[$index - 1] !== ':')
                && $next !== ':'
                && $next !== null
                && self::isIdentifierStart($next)) {
                $start = $index + 1;
                $end = $start + 1;
                while ($end < $length && self::isIdentifierPart($sql[$end])) {
                    $end++;
                }
                $token = substr($sql, $index, $end - $index);
                $index = $end;
            } else {
                $parts[] = $ch;
                $index++;
                continue;
            }

            $replacement = $replaceToken($token);
            if (!$replacement instanceof SqlParameter) {
                $parts[] = $replacement;
                continue;
            }
            if ($replacement->kind === SqlParameter::LIST) {
                $parts[] = self::sqlLiteral($replacement);
                continue;
            }
            $scalar = $replacement->asValue();
            if ($scalar->kind !== Value::STRING) {
                $parts[] = self::valueLiteral($scalar);
                continue;
            }
            // Append a large string in pieces so the final statement holds the
            // only copy of it.
            $text = $scalar->asString();
            $parts[] = "'";
            $parts[] = str_contains($text, "'") ? str_replace("'", "''", $text) : $text;
            $parts[] = "'";
        }
        return $parts;
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
