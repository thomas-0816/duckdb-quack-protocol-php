<?php

/**
 * Basic querying: metadata, lazy rows, and the fetch helpers.
 *
 * Run: php examples/01_basic_query.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DuckDB\Quack\QuackClient;

error_reporting(E_ALL);
ini_set('display_errors', 1);

// start the duckdb server
// duckdb --cmd "CALL quack_serve('quack:127.0.0.1:9494', token='secret');"

$client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 10, 300);
echo 'connected with Quack protocol version ', $client->getConnection()->quackVersion(), PHP_EOL;

$sql = "SELECT 1 as a, 'foo' as b, [1, 2, 3] as c, null as d, true as e, {bar: 'baz'} as f, ? as g";

echo PHP_EOL, 'rows:', PHP_EOL;
foreach ($client->fetchIterator($sql, ['foo']) as $row) {
    print_r($row);
}

echo PHP_EOL, 'fetchAll():', PHP_EOL;
print_r($client->fetchAll($sql, [['foo' => 'bar']]));

// The fetch helpers consume the stream for you.
$first = $client->fetchOne('SELECT 42 AS answer') ?? [];
echo PHP_EOL, 'fetchRow(): ';
var_dump($first['answer'] ?? null);

$value = $client->fetchValue('SELECT 7 AS answer');
echo 'fetchValue(): ';
var_dump($value);

$values = $client->fetchColumn('SELECT * FROM (VALUES (10), (20), (30)) t(x)');
echo 'fetchColumn(): ';
print_r($values);

// execute() runs a statement and returns its changed-row count.
$created = $client->execute('CREATE TEMP TABLE example_basic(id INTEGER, label VARCHAR)');
$inserted = $client->execute("INSERT INTO example_basic VALUES (1, 'one'), (2, 'two')");
echo PHP_EOL, 'changed rows: create=', $created, ', insert=', $inserted, PHP_EOL;
$rows = $client->fetchAll('SELECT * FROM example_basic');
echo PHP_EOL, 'temp table rows: ', count($rows), PHP_EOL;

echo PHP_EOL, 'done', PHP_EOL;
