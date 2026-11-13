<?php

declare(strict_types=1);

/**
 * Basic querying: metadata, lazy rows, and the one-shot helpers.
 *
 * Run: php examples/01_basic_query.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Quack\Protocol\QuackClient;

echo 'connecting to http://127.0.0.1:9494', PHP_EOL;
$client = QuackClient::connect(
    'http://127.0.0.1:9494',
    'secret',
    10.0,
    300.0,
);

$info = $client->info;
echo 'server DuckDB: ', $info?->serverDuckdbVersion ?? 'unknown', PHP_EOL;
echo 'Quack version: ', $info?->quackVersion ?? 'unknown', PHP_EOL;

// A result stream is consumed lazily. Querying does not download all rows.
$stream = $client->query(
    'SELECT * FROM (VALUES '
    . "(1::INTEGER, 'one'::VARCHAR), "
    . "(2, 'two'), "
    . '(3, NULL)) AS t(id, label) ORDER BY id',
);

echo PHP_EOL, 'columns:', PHP_EOL;
foreach ($stream->columns as $index => $column) {
    echo '  ', $index, ': ', $column->name,
    ' [', $column->logicalType->id->name, ']', PHP_EOL;
}

echo PHP_EOL, 'rows:', PHP_EOL;
foreach ($stream->rows() as $row) {
    print_r($row);
}

// The consuming helpers close the stream for you.
$first = $client->query('SELECT 42 AS answer')->first();
echo PHP_EOL, 'first(): ';
var_dump($first['answer'] ?? null);

$one = $client->query('SELECT 7 AS answer')->one();
echo 'one(): ';
var_dump($one['answer'] ?? null);

$values = $client->query('SELECT * FROM (VALUES (10), (20), (30)) t(x)')->column('x');
echo 'values(): ';
print_r($values);

// execute() drains a statement that has no interesting result.
$client->execute('CREATE TEMP TABLE example_basic(id INTEGER, label VARCHAR)');
$client->execute("INSERT INTO example_basic VALUES (1, 'one'), (2, 'two')");
$rows = iterator_to_array($client->query('SELECT * FROM example_basic')->rows());
echo PHP_EOL, 'temp table rows: ', count($rows), PHP_EOL;

echo PHP_EOL, 'done', PHP_EOL;
