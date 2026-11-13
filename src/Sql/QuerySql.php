<?php

declare(strict_types=1);

namespace Quack\Protocol\Sql;

/**
 * A raw SQL string plus a short collapsed preview that is safe to log.
 */
final class QuerySql
{
    private const HEAD_LEN = 10;
    private const TAIL_LEN = 10;

    public readonly string $raw;
    public readonly string $preview;

    public function __construct(string $raw)
    {
        $this->raw = $raw;
        $parts = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
        $collapsed = $parts === false ? trim($raw) : implode(' ', $parts);
        $chars = preg_split('//u', $collapsed, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            $chars = str_split($collapsed);
        }
        $count = count($chars);
        $this->preview = $count <= self::HEAD_LEN + self::TAIL_LEN
            ? $collapsed
            : implode('', array_slice($chars, 0, self::HEAD_LEN))
                . '...'
                . implode('', array_slice($chars, $count - self::TAIL_LEN));
    }

    public function asString(): string
    {
        return $this->raw;
    }

    public function __toString(): string
    {
        return $this->preview;
    }
}
