<?php

declare(strict_types=1);

namespace Quack\Protocol\Error;

/** An optional error detail recognized from DuckDB's extra-info map. */
final class ErrorSubtype
{
    /** @var array<string,array{0:string,1:string}>|null */
    private static ?array $lookup = null;

    private function __construct(
        private readonly string $canonical,
        private readonly string $raw,
        private readonly string $name,
        private readonly bool $unknown,
    ) {
    }

    public static function from(string $value): self
    {
        self::$lookup ??= [
            'MISSING_ENTRY' => ['MISSING_ENTRY', 'MissingEntry'],
            'ENTRY_ALREADY_EXISTS' => ['ENTRY_ALREADY_EXISTS', 'EntryAlreadyExists'],
            'COLUMN_NOT_FOUND' => ['COLUMN_NOT_FOUND', 'ColumnNotFound'],
            'NO_MATCHING_FUNCTION' => ['NO_MATCHING_FUNCTION', 'NoMatchingFunction'],
            'UNSUPPORTED' => ['UNSUPPORTED', 'Unsupported'],
            'SYNTAX_ERROR' => ['SYNTAX_ERROR', 'SyntaxError'],
        ];
        $entry = self::$lookup[$value] ?? null;
        if ($entry === null) {
            return new self($value, $value, 'Unknown', true);
        }
        return new self($entry[0], $value, $entry[1], false);
    }

    public function asString(): string
    {
        return $this->unknown ? $this->raw : $this->canonical;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function isUnknown(): bool
    {
        return $this->unknown;
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && (!$this->unknown || $this->raw === $other->raw);
    }

    public function __toString(): string
    {
        return $this->unknown ? 'Unknown' : $this->name;
    }
}
