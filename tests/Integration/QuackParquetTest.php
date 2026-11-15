<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackParquetTest extends TestCase
{
    public function testParquetMetadata(): void
    {
        $jsonFile = sys_get_temp_dir() . '/quack_parquet_' . bin2hex(random_bytes(8)) . '.ndjson';
        $parquetFile = sys_get_temp_dir() . '/quack_parquet_' . bin2hex(random_bytes(8)) . '.parquet';
        $parquetFile2 = sys_get_temp_dir() . '/quack_parquet_' . bin2hex(random_bytes(8)) . '.parquet';

        file_put_contents($jsonFile, json_encode(['date' => '2026-01-02 03:04:05', 'log' => 'log text']) . PHP_EOL);
        file_put_contents($jsonFile, json_encode(['date' => '2026-02-03 04:05:06', 'log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

        try {
            $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 60);
            // Limit threads and memory usage for converting big files, see
            // https://github.com/duckdb/duckdb/issues/16078
            $client->execute("SET memory_limit='4GB'");
            $client->execute('SET threads = 1');
            $client->execute('SET preserve_insertion_order = false');
            // 100k rows per group
            $client->execute(
                "COPY (SELECT * FROM read_ndjson('{$jsonFile}', ignore_errors=true)) "
                . "TO '{$parquetFile}' (FORMAT 'parquet', COMPRESSION 'zstd', ROW_GROUP_SIZE 100_000)",
            );
            // 100M bytes per group
            $client->execute(
                "COPY (SELECT * FROM read_ndjson('{$jsonFile}', ignore_errors=true)) "
                . "TO '{$parquetFile2}' (FORMAT 'parquet', COMPRESSION 'zstd', ROW_GROUP_SIZE_BYTES 100_000_000)",
            );

            self::assertSame(
                ['3.7 GiB', '1'],
                $client->fetchColumn(
                    "SELECT value FROM duckdb_settings() WHERE name IN ('threads', 'memory_limit')",
                ),
            );

            $expected = [['date' => '2026-01-02 03:04:05', 'log' => 'log text'], ['date' => '2026-02-03 04:05:06', 'log' => 'log text 2']];

            self::assertSame($expected, $client->fetchAll("select * from '{$parquetFile}'"));
            self::assertSame($expected, $client->fetchAll("select * from '{$parquetFile2}'"));
        } finally {
            @unlink($jsonFile);
            @unlink($parquetFile);
            @unlink($parquetFile2);
        }
    }
}
