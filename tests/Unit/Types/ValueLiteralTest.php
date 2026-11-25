<?php

namespace DuckDB\Quack\Tests\Unit\Types;

use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Types\Value;
use PHPUnit\Framework\TestCase;

final class ValueLiteralTest extends TestCase
{
    public function testFloatLiterals(): void
    {
        self::assertSame('1.5', Value::float(1.5)->asFloatLiteral());
        self::assertSame('-1.5', Value::float(-1.5)->asFloatLiteral());
        self::assertSame("'NaN'::FLOAT", Value::float(NAN)->asFloatLiteral());
        self::assertSame("'Infinity'::FLOAT", Value::float(INF)->asFloatLiteral());
        self::assertSame("'-Infinity'::FLOAT", Value::float(-INF)->asFloatLiteral());
    }

    public function testDoubleLiterals(): void
    {
        self::assertSame('1.5', Value::double(1.5)->asDoubleLiteral());
        self::assertSame('-1.5', Value::double(-1.5)->asDoubleLiteral());
        self::assertSame("'NaN'::DOUBLE", Value::double(NAN)->asDoubleLiteral());
        self::assertSame("'Infinity'::DOUBLE", Value::double(INF)->asDoubleLiteral());
        self::assertSame("'-Infinity'::DOUBLE", Value::double(-INF)->asDoubleLiteral());
    }

    public function testLiteralMethodsRejectOtherKinds(): void
    {
        $calls = [
            static fn (): string => Value::double(1.5)->asFloatLiteral(),
            static fn (): string => Value::float(1.5)->asDoubleLiteral(),
            static fn (): string => Value::int(1)->asFloatLiteral(),
        ];

        foreach ($calls as $call) {
            try {
                $call();
                self::fail('expected a value kind mismatch');
            } catch (ProtocolException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
