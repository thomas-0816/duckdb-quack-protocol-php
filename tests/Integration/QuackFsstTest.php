<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;
use Throwable;

final class QuackFsstTest extends TestCase
{
    public function testDictFsstStringScanDecodes(): void
    {
        $database = sys_get_temp_dir() . '/quack_fsst_' . bin2hex(random_bytes(8)) . '.duckdb';
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        try {
            $client->execute('SET enable_fsst_vectors = true');
            $client->execute("SET force_compression = 'dict_fsst'");
            $client->execute('ATTACH ? AS fsst_db', [$database]);
            $client->execute('CREATE TABLE fsst_db.fsst_values (s VARCHAR)');
            $client->execute(
                "INSERT INTO fsst_db.fsst_values
                    SELECT 'the quick brown fox jumps over the lazy dog ' || (i % 100)::VARCHAR
                    FROM generate_series(1, 10_000) t(i)",
            );
            $client->execute('CHECKPOINT fsst_db');

            self::assertContains(
                'DICT_FSST',
                $client->fetchColumn("SELECT DISTINCT compression FROM pragma_storage_info('fsst_db.fsst_values')"),
            );

            self::assertSame(10_000, $client->fetchValue('SELECT count(*) FROM fsst_db.fsst_values'));
            self::assertSame(
                [['s' => 'the quick brown fox jumps over the lazy dog 0']],
                $client->fetchAll('SELECT s FROM fsst_db.fsst_values ORDER BY s LIMIT 1'),
            );
            self::assertSame(10_000, $client->fetchValue("SELECT count(*) FROM fsst_db.fsst_values WHERE s LIKE 'the quick brown fox%'"));
        } finally {
            try {
                $client->execute('DETACH fsst_db');
            } catch (Throwable) {
                // The attach can fail before the database exists.
            }
            $client->execute("SET force_compression = 'auto'");
            $client->execute('SET enable_fsst_vectors = false');

            @unlink($database);
            @unlink($database . '.wal');
        }
    }
}
