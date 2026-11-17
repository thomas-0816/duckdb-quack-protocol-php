<?php

/**
 * Benchmark
 *
 * Start the DuckDB server:
 * duckdb --cmd "CALL quack_serve('quack:127.0.0.1:9494', token='secret');"
 *
 * Run: php examples/04_benchmark_select.php
 */

use DuckDB\Quack\QuackClient;

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

// default quack_prepare_inline_rows = 24576
$start = microtime(true);
$result = $db->fetchAll('select s from range(1_000_000) t(s)');
echo microtime(true) - $start, PHP_EOL;

var_dump([$result[0], $result[999999]]);

echo memory_get_peak_usage(true) / (1024 * 1024), PHP_EOL;
