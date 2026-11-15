<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackTransactionTest extends TestCase
{
    public function testCommitAndRollback(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE txn_test (id INTEGER, val VARCHAR)');

        $client->beginTransaction();
        $client->execute('INSERT INTO txn_test VALUES (?, ?)', [1, 'committed']);
        $client->commit();

        self::assertSame(
            [['id' => 1, 'val' => 'committed']],
            $client->fetchAll('SELECT * FROM txn_test'),
        );

        $client->beginTransaction();
        $client->execute('INSERT INTO txn_test VALUES (?, ?)', [2, 'rolled_back']);
        $client->rollback();

        self::assertSame(
            [['id' => 1, 'val' => 'committed']],
            $client->fetchAll('SELECT * FROM txn_test'),
        );
    }

    public function testSqlTransactionStatements(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE t1 (id INTEGER)');

        $client->execute('BEGIN');
        $client->execute('INSERT INTO t1 VALUES (1)');
        $client->execute('COMMIT');

        $client->execute('BEGIN');
        $client->execute('INSERT INTO t1 VALUES (1)');
        $client->rollback();

        $client->execute('BEGIN');
        $client->execute('INSERT INTO t1 VALUES (1)');
        $client->commit();

        $client->beginTransaction();
        $client->execute('INSERT INTO t1 VALUES (1)');
        $client->execute('COMMIT');

        $client->beginTransaction();
        $client->execute('INSERT INTO t1 VALUES (1)');
        $client->execute('ROLLBACK');

        // Five inserts, one of which was rolled back.
        self::assertSame(3, $client->fetchValue('SELECT count(*) FROM t1'));
    }

    public function testStatementErrorAbortsTransaction(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE t1 (id INTEGER)');

        $client->beginTransaction();
        $client->execute('INSERT INTO t1 VALUES (1)');

        try {
            $client->execute('SELECT INVALID');
            self::fail('expected the query to fail');
        } catch (ServerException $e) {
            self::assertStringContainsString('Referenced column "INVALID" was not found because the FROM clause is missing', $e->getMessage());
        }

        // The failed statement aborts the transaction. COMMIT then rolls the
        // transaction back, matching DuckDB through PDO as well.
        $client->execute('COMMIT');

        self::assertSame(0, $client->fetchValue('SELECT count(*) FROM t1'));
    }

    public function testTransactionStateErrors(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        try {
            $client->rollback();
            self::fail('expected the rollback to fail');
        } catch (ServerException $e) {
            self::assertStringContainsString('cannot rollback - no transaction is active', $e->getMessage());
        }

        try {
            $client->commit();
            self::fail('expected the commit to fail');
        } catch (ServerException $e) {
            self::assertStringContainsString('cannot commit - no transaction is active', $e->getMessage());
        }

        $client->beginTransaction();

        try {
            $client->beginTransaction();
            self::fail('expected the nested begin to fail');
        } catch (ServerException $e) {
            self::assertStringContainsString('cannot start a transaction within a transaction', $e->getMessage());
        }

        $client->rollback();
    }

    public function testTwoConnectionsSeeCommittedRows(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $table = "quack_txn_visibility_{$suffix}";

        $a = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $b = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $a->execute("CREATE TABLE {$table} (i1 INTEGER)");

        try {
            $a->beginTransaction();
            $a->execute("INSERT INTO {$table} VALUES (42)");

            self::assertSame(0, $b->fetchValue("SELECT count(*) FROM {$table}"));

            $a->commit();

            self::assertSame(1, $b->fetchValue("SELECT count(*) FROM {$table}"));
            self::assertSame(1, $a->fetchValue("SELECT count(*) FROM {$table}"));
        } finally {
            $a->execute("DROP TABLE {$table}");
        }
    }
}
