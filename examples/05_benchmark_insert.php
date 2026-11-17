<?php

/**
 * Benchmark
 *
 * Start the DuckDB server:
 * duckdb --cmd "CALL quack_serve('quack:127.0.0.1:9494', token='secret');"
 *
 * Run: php examples/05_benchmark_insert.php
 */

use DuckDB\Quack\QuackClient;

error_reporting(E_ALL);
ini_set('display_errors', 1);

require __DIR__ . '/../vendor/autoload.php';

$data = [];
for ($i = 0; $i < 1_000_000; $i++) {
    $data[] = [$i, 'foo' . $i];
}

$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
$db->execute('CREATE TABLE t1_b1 (i1 integer, v1 varchar)');
$db->getConnection()->getTransport()->setDebug(true);

$start = microtime(true);
$db->execute('INSERT INTO t1_b1 SELECT value->>0, value->>1 FROM json_each(?)', [$data]);
echo microtime(true) - $start, PHP_EOL;

$db->getConnection()->getTransport()->setDebug(false);
echo $db->fetchValue('SELECT count(*) from t1_b1'), PHP_EOL; // 1000000
$db->execute('DROP TABLE t1_b1');

$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
$db->execute('CREATE TABLE t1_b2 (i1 integer, v1 varchar)');
$db->getConnection()->getTransport()->setDebug(true);

$start = microtime(true);
$db->execute("INSERT INTO t1_b2 SELECT value->>0, value->>1 FROM json_each('" . json_encode($data) . "')");
echo microtime(true) - $start, PHP_EOL;

$db->getConnection()->getTransport()->setDebug(false);
echo $db->fetchValue('SELECT count(*) from t1_b2'), PHP_EOL; // 1000000
$db->execute('DROP TABLE t1_b2');


$data = [];
for ($i = 0; $i < 1_000_000; $i++) {
    $data[] = ['i1' => $i, 'v1' => 'foo' . $i];
}

$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
$db->execute('CREATE TABLE t1_b3 (i1 integer, v1 varchar)');
$db->getConnection()->getTransport()->setDebug(true);

$start = microtime(true);
$db->execute("INSERT INTO t1_b3 SELECT value->>'i1', value->>'v1' FROM json_each(?)", [$data]);
echo microtime(true) - $start, PHP_EOL;

$db->getConnection()->getTransport()->setDebug(false);
echo $db->fetchValue('SELECT count(*) from t1_b3'), PHP_EOL; // 1000000
$db->execute('DROP TABLE t1_b3');


$db = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
$db->execute('CREATE TABLE t1_b4 (i1 integer, v1 varchar)');
$db->getConnection()->getTransport()->setDebug(true);

$start = microtime(true);
$db->execute("INSERT INTO t1_b4 SELECT value->>'i1', value->>'v1' FROM json_each('" . json_encode($data) . "')");
echo microtime(true) - $start, PHP_EOL;

$db->getConnection()->getTransport()->setDebug(false);
echo $db->fetchValue('SELECT count(*) from t1_b4'), PHP_EOL; // 1000000
$db->execute('DROP TABLE t1_b4');

unset($data);
gc_collect_cycles();


echo memory_get_peak_usage(true) / (1024 * 1024), PHP_EOL;
