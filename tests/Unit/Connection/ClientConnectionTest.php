<?php

namespace DuckDB\Quack\Tests\Unit\Connection;

use DuckDB\Quack\Connection\ClientConnection;
use DuckDB\Quack\Exceptions\ProtocolException;
use DuckDB\Quack\Types\HugeIntParts;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

final class ClientConnectionTest extends TestCase
{
    public function testNewConnectionIsOpen(): void
    {
        self::assertFalse(self::connection()->isClosed());
    }

    public function testRetiredConnectionRejectsPrepare(): void
    {
        $connection = self::retiredConnection();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Quack client is not connected');

        $connection->prepare('SELECT 1');
    }

    public function testRetiredConnectionRejectsFetch(): void
    {
        $connection = self::retiredConnection();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('Quack client is not connected');

        $connection->fetch(new HugeIntParts(0, 1), 1, 0);
    }

    public function testDisconnectOnDropIsNoOpForRetiredConnection(): void
    {
        $connection = self::retiredConnection();

        $connection->disconnectOnDrop();

        self::assertTrue($connection->isClosed());
    }

    private static function connection(): ClientConnection
    {
        /** @var ClientConnection $connection */
        $connection = (new ReflectionClass(ClientConnection::class))->newInstanceWithoutConstructor();

        return $connection;
    }

    private static function retiredConnection(): ClientConnection
    {
        $connection = self::connection();
        (new ReflectionProperty(ClientConnection::class, 'closed'))->setValue($connection, true);

        return $connection;
    }
}
