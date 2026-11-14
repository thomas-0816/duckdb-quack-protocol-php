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
print_r($client->info);

$stream = $client->query("SELECT 1, 'foo', [1, 2, 3], null, true, {bar: 'baz'}");
print_r($stream->fetchAll());
exit;

echo PHP_EOL, 'columns:', PHP_EOL;
foreach ($stream->columns() as $name => $type) {
    echo '  ', $name, ' [', $type->id->name, ']', PHP_EOL;
}

echo PHP_EOL, 'rows:', PHP_EOL;
foreach ($stream as $row) {
    print_r($row);
}

// The fetch helpers consume the stream for you.
$first = $client->query('SELECT 42 AS answer')->fetchRow();
echo PHP_EOL, 'fetchRow(): ';
var_dump($first['answer'] ?? null);

$value = $client->query('SELECT 7 AS answer')->fetchValue('answer');
echo 'fetchValue(): ';
var_dump($value);

$values = $client->query('SELECT * FROM (VALUES (10), (20), (30)) t(x)')->fetchColumn('x');
echo 'fetchColumn(): ';
print_r($values);

// execute() runs a statement and returns its changed-row count.
$created = $client->execute('CREATE TEMP TABLE example_basic(id INTEGER, label VARCHAR)');
$inserted = $client->execute("INSERT INTO example_basic VALUES (1, 'one'), (2, 'two')");
echo PHP_EOL, 'changed rows: create=', $created, ', insert=', $inserted, PHP_EOL;
$rows = iterator_to_array($client->query('SELECT * FROM example_basic'));
echo PHP_EOL, 'temp table rows: ', count($rows), PHP_EOL;

echo PHP_EOL, 'done', PHP_EOL;
