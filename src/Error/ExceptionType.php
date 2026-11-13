<?php

declare(strict_types=1);

namespace Quack\Protocol\Error;

/**
 * A known DuckDB exception category, or an unchanged future wire value.
 *
 * Known names follow DuckDB 1.5/2.0's ExceptionTypeToString mapping, with
 * explicit legacy aliases. Unknown values preserve their original text.
 */
final class ExceptionType
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
        self::$lookup ??= self::buildLookup();
        $entry = self::$lookup[$value] ?? null;
        if ($entry === null) {
            return new self($value, $value, 'Unknown', true);
        }
        return new self($entry[0], $value, $entry[1], false);
    }

    /** @return array<string,array{0:string,1:string}> */
    private static function buildLookup(): array
    {
        $table = [
            'Invalid' => [['Invalid'], 'Invalid'],
            'Out of Range' => [['Out of Range'], 'OutOfRange'],
            'Conversion' => [['Conversion', 'CONVERSION'], 'Conversion'],
            'Unknown Type' => [['Unknown Type'], 'UnknownType'],
            'Decimal' => [['Decimal'], 'Decimal'],
            'Mismatch Type' => [['Mismatch Type'], 'MismatchType'],
            'Divide by Zero' => [['Divide by Zero'], 'DivideByZero'],
            'Object Size' => [['Object Size'], 'ObjectSize'],
            'Invalid type' => [['Invalid type'], 'InvalidType'],
            'Serialization' => [['Serialization'], 'Serialization'],
            'TransactionContext' => [['TransactionContext', 'Transaction', 'TRANSACTION'], 'Transaction'],
            'Not implemented' => [['Not implemented', 'Not Implemented', 'NOT IMPLEMENTED'], 'NotImplemented'],
            'Expression' => [['Expression'], 'Expression'],
            'Catalog' => [['Catalog', 'CATALOG'], 'Catalog'],
            'Parser' => [['Parser', 'PARSER'], 'Parser'],
            'Binder' => [['Binder', 'BINDER'], 'Binder'],
            'Planner' => [['Planner'], 'Planner'],
            'Scheduler' => [['Scheduler'], 'Scheduler'],
            'Executor' => [['Executor'], 'Executor'],
            'Constraint' => [['Constraint', 'CONSTRAINT'], 'Constraint'],
            'Index' => [['Index'], 'Index'],
            'Stat' => [['Stat'], 'Stat'],
            'Connection' => [['Connection', 'CONNECTION'], 'Connection'],
            'Syntax' => [['Syntax'], 'Syntax'],
            'Settings' => [['Settings'], 'Settings'],
            'Optimizer' => [['Optimizer'], 'Optimizer'],
            'NullPointer' => [['NullPointer'], 'NullPointer'],
            'IO' => [['IO'], 'Io'],
            'INTERRUPT' => [['INTERRUPT', 'Interrupt'], 'Interrupt'],
            'FATAL' => [['FATAL', 'Fatal'], 'Fatal'],
            'INTERNAL' => [['INTERNAL', 'Internal'], 'Internal'],
            'Invalid Input' => [['Invalid Input', 'INVALID INPUT'], 'InvalidInput'],
            'Out of Memory' => [['Out of Memory', 'OUT OF MEMORY'], 'OutOfMemory'],
            'Permission' => [['Permission', 'PERMISSION'], 'Permission'],
            'Parameter Not Resolved' => [['Parameter Not Resolved'], 'ParameterNotResolved'],
            'Parameter Not Allowed' => [['Parameter Not Allowed'], 'ParameterNotAllowed'],
            'Dependency' => [['Dependency'], 'Dependency'],
            'Missing Extension' => [['Missing Extension'], 'MissingExtension'],
            'HTTP' => [['HTTP'], 'Http'],
            'Extension Autoloading' => [['Extension Autoloading'], 'ExtensionAutoloading'],
            'Sequence' => [['Sequence'], 'Sequence'],
            'Invalid Configuration' => [['Invalid Configuration', 'INVALID CONFIGURATION'], 'InvalidConfiguration'],
            'Data Corruption' => [['Data Corruption'], 'DataCorruption'],
            'Resource In Use' => [['Resource In Use'], 'ResourceInUse'],
        ];
        $lookup = [];
        foreach ($table as $canonical => [$aliases, $name]) {
            foreach ($aliases as $alias) {
                $lookup[$alias] = [$canonical, $name];
            }
        }
        return $lookup;
    }

    /** The canonical wire name, or the original unknown text. */
    public function asString(): string
    {
        return $this->unknown ? $this->raw : $this->canonical;
    }

    /** The exact text received from the server. */
    public function raw(): string
    {
        return $this->raw;
    }

    /** The classification name, or "Unknown". */
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
