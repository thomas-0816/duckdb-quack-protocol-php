<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackCancelTest extends TestCase
{
    public function testAbandonedStreamCancelsServerQuery(): void
    {
        $admin = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $previousInlineRows = $admin->fetchValue("SELECT current_setting('quack_prepare_inline_rows')");
        self::assertIsInt($previousInlineRows);

        try {
            $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
            $connectionId = $client->getConnection()->connectionId();

            self::assertSame('idle', $admin->fetchValue('SELECT state FROM quack_active_connections() WHERE connection_id = ?', [$connectionId]));
            $admin->execute('SET quack_prepare_inline_rows = 0');

            // fetchValue() consumes only the first row and drops the generator, which
            // must cancel the still-open server-side result stream.
            self::assertSame(0, $client->fetchValue('SELECT i FROM range(100) t(i)'));
            self::assertSame('cancelled', $admin->fetchValue('SELECT state FROM quack_active_connections() WHERE connection_id = ?', [$connectionId]));

            $client->execute('SET quack_prepare_inline_rows = 2');
            self::assertSame(42, $client->fetchValue('SELECT 42'));
            self::assertSame('finished', $admin->fetchValue('SELECT state FROM quack_active_connections() WHERE connection_id = ?', [$connectionId]));
        } finally {
            $admin->execute('SET quack_prepare_inline_rows = ' . $previousInlineRows);
        }
    }
}
