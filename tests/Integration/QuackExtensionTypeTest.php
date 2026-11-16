<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackExtensionTypeTest extends TestCase
{
    public function testReadsAggregateStateExtensionTypes(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['s' => 1]],
            $client->fetchAll("SELECT to_aggregate_state(1, 'sum', ['INTEGER']) AS s"),
        );
        self::assertSame(
            [['s' => ['precision' => 10, 'registers' => [1, 2, 3]]]],
            $client->fetchAll("SELECT to_aggregate_state({'precision': 10, 'registers': [1, 2, 3]::UTINYINT[]}, 'approx_count_distinct', ['BIGINT']) AS s"),
        );
    }
}
