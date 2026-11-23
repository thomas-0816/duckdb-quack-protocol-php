<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\Exceptions\TransportException;
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
            $connectionId = $client->getConnection()->getConnectionId();

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

    public function testTimeoutCancelsServerQuery(): void
    {
        $admin = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $connectionId = $client->getConnection()->getConnectionId();

        $client->getConnection()->getTransport()->setTimeout(1);

        try {
            $client->fetchValue('SELECT sum(i) FROM range(100000000000) t(i)');
            self::fail('expected the slow query to time out');
        } catch (TransportException $error) {
            self::assertStringContainsStringIgnoringCase('timed out', $error->getMessage());
        }

        // The server keeps running a statement after the HTTP request times
        // out. Retiring the connection must interrupt it, or its transaction
        // outlives the client and blocks later CHECKPOINTs.
        self::assertSame('cancelled', $admin->fetchValue('SELECT state FROM quack_active_connections() WHERE connection_id = ?', [$connectionId]));
    }
}
