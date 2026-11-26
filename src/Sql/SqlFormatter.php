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
     * named. Positional `?` cannot be combined with named placeholders.
     *
     * `$name` is not a placeholder: it is left untouched as a DuckDB variable
     * reference, for example with `SET VARIABLE foo = 'bar'; SELECT $foo;`.
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
            Value::FLOAT => $value->asFloatLiteral(),
            Value::DOUBLE => $value->asDoubleLiteral(),
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
                if ($token[0] === ':') {
                    throw new ProtocolException("cannot mix positional '?' placeholders with named {$token} placeholders");
                }
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
            if ($token === '?') {
                throw new ProtocolException("cannot mix named placeholders with positional '?' placeholders");
            }
            if ($token[0] !== ':') {
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
     * Replaces tokens outside single quotes, double quotes, escape strings,
     * dollar-quoted strings, line comments, and block comments.
     *
     * @param callable(string):string $replaceToken
     */
    public static function scanSql(string $sql, callable $replaceToken): string
    {
        return implode('', self::scanSqlParts($sql, static fn (string $token): string => $replaceToken($token)));
    }

    // This scanner is the equivalent of the lexer behind PDO's emulated
    // prepares (PDO::ATTR_EMULATE_PREPARES), implemented in php-src by
    // pdo_parse_params() with the re2c grammars in ext/pdo/pdo_sql_parser.re
    // and ext/pdo_pgsql/pgsql_sql_parser.re.
    //
    // Adapted from pdo_pgsql, the grammar translates to roughly this PCRE
    // pattern. PCRE alternation is first-match while re2c uses longest-match,
    // so the alternatives are ordered by hand:
    //
    //   ~\G(?:
    //       (?<dquote> " (?: [^"]+ | "" )* " )
    //     | (?<squote> ' (?: [^']+ | '' )* ' )
    //     | (?<dollar> \$ (?:(?<tag>[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*))?
    //                     \$ [\s\S]*? \$(?(tag)\k<tag>\$|\$) )
    //     | (?<multichar> ::+ )
    //     | (?<bind> (?<![A-Za-z0-9]) :[A-Za-z0-9_]+ )
    //     | (?<question> \? )
    //     | (?<comment> /\* (?: [^*]+ | \*+[^*/] )* \*+ / | --[^\n]* )
    //     | (?<unterminated> (?: " | ' ) [\s\S]* \z )
    //     | (?<text> (?: [^$:?"'/-] )+ )
    //     | (?<special> [\s\S] )
    //   )~x
    //
    // The negative lookbehind on `bind` reproduces PDO's rule that a bind
    // preceded by an alphanumeric byte is not a placeholder. It succeeds at
    // the start of the subject.
    //
    // DuckDB's `e'...'` literals are scanned as regular strings: `e` is
    // matched by `text`, the quote by `squote`, and a quote is escaped with
    // `''`, not with `\'`, so there is no separate `equote` alternative.
    //
    // A regex-based implementation would then loop over the remaining input:
    //
    //   $offset = 0;
    //   while ($offset < strlen($sql)) {
    //       if (preg_match($pattern, $sql, $match, 0, $offset) !== 1) {
    //           throw new ProtocolException(preg_last_error_msg());
    //       }
    //       $out .= $replaceToken($match[0]);
    //       $offset += strlen($match[0]);
    //   }
    //
    // The explicit loop below is used instead because:
    //  - it is faster: the PCRE version was measured 1.9-5.5x slower with
    //    pcre.jit and 2.4-12x slower without it (PHP 8.6);
    //  - PCRE JIT can fail with PREG_JIT_STACKLIMIT_ERROR on ordinary large
    //    literals or comments, for example a 40 KB single-quoted string;
    //  - re2c's longest-match has to be recreated by hand with ordered
    //    alternatives;
    //  - dollar quotes are a custom-quote state in pdo_pgsql and are simpler
    //    with strpos() than with a conditional backreference.
    //
    // Both implementations copy literals and comments verbatim; only '?' and
    // ':name' tokens outside them are passed to the replacement callback.
    // `$name` is copied verbatim too, so DuckDB variables such as
    // `SET VARIABLE foo = 'bar'; SELECT $foo;` keep working.
    /**
     * The scan behind {@see self::scanSql()}. A replacement may be a parameter
     * literal instead of a string, so it can be appended in pieces and joined
     * into the statement once instead of being built into an intermediate
     * string first.
     *
     * Handles single-quoted strings (including `e'...'`), double-quoted
     * identifiers, dollar-quoted strings (`$$...$$`, `$tag$...$tag$`), and
     * comments. Dollar variable references (`$name`) are left untouched.
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
            $span = strcspn($sql, "'\"-/:?$", $index);
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
                // `e'...'` uses `''` for quotes, so it scans like a regular
                // string; a backslash does not escape the closing quote.
                $end = $length;
                $scan = $index + 1;
                while (($quote = strpos($sql, "'", $scan)) !== false) {
                    if ($quote + 1 < $length && $sql[$quote + 1] === "'") {
                        $scan = $quote + 2;
                        continue;
                    }
                    $end = $quote + 1;
                    break;
                }
                $parts[] = substr($sql, $index, $end - $index);
                $index = $end;
                continue;
            }
            if ($ch === '"') {
                $end = $length;
                $scan = $index + 1;
                while (($quote = strpos($sql, '"', $scan)) !== false) {
                    if ($quote + 1 < $length && $sql[$quote + 1] === '"') {
                        $scan = $quote + 2;
                        continue;
                    }
                    $end = $quote + 1;
                    break;
                }
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
                && ($next === '_'
                    || ($next >= 'a' && $next <= 'z')
                    || ($next >= 'A' && $next <= 'Z')
                    || ($next >= '0' && $next <= '9'))
                && ($index === 0
                    || !(($sql[$index - 1] >= 'a' && $sql[$index - 1] <= 'z')
                        || ($sql[$index - 1] >= 'A' && $sql[$index - 1] <= 'Z')
                        || ($sql[$index - 1] >= '0' && $sql[$index - 1] <= '9')))) {
                // PDO only treats a bind as a placeholder when it is not
                // preceded by an alphanumeric byte. This keeps DuckDB slices
                // like `list[1:2]` and identifier-like text such as `foo:bar`
                // intact. Names may start with a digit, matching PDO's
                // `:[A-Za-z0-9_]+` grammar.
                $start = $index + 1;
                $end = $start + 1;
                while ($end < $length) {
                    $part = $sql[$end];
                    if ($part !== '_'
                        && ($part < 'a' || $part > 'z')
                        && ($part < 'A' || $part > 'Z')
                        && ($part < '0' || $part > '9')) {
                        break;
                    }
                    $end++;
                }
                $token = substr($sql, $index, $end - $index);
                $index = $end;
            } elseif ($ch === '$') {
                // DuckDB dollar quotes: $$...$$ or $tag$...$tag$. Tags follow
                // identifier rules and are case-sensitive. Anything else,
                // including `$name` variable references and `$1`, is text.
                $delimiter = null;
                $scan = $index + 1;
                if ($scan < $length) {
                    if ($sql[$scan] === '$') {
                        $delimiter = '$$';
                    } else {
                        $tag = $sql[$scan];
                        if ($tag === '_'
                            || ($tag >= 'a' && $tag <= 'z')
                            || ($tag >= 'A' && $tag <= 'Z')
                            || ord($tag) >= 0x80) {
                            $end = $scan + 1;
                            while ($end < $length) {
                                $tag = $sql[$end];
                                if ($tag !== '_'
                                    && ($tag < 'a' || $tag > 'z')
                                    && ($tag < 'A' || $tag > 'Z')
                                    && ($tag < '0' || $tag > '9')
                                    && ord($tag) < 0x80) {
                                    break;
                                }
                                $end++;
                            }
                            if ($end < $length && $sql[$end] === '$') {
                                $delimiter = substr($sql, $index, $end + 1 - $index);
                            }
                        }
                    }
                }
                if ($delimiter !== null) {
                    $close = strpos($sql, $delimiter, $index + strlen($delimiter));
                    $end = $close === false ? $length : $close + strlen($delimiter);
                    $parts[] = substr($sql, $index, $end - $index);
                    $index = $end;
                    continue;
                }
                $parts[] = $ch;
                $index++;
                continue;
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
}
