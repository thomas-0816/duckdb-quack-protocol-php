<?php

/**
 * Streaming results.
 *
 * Queries return a lazy result stream. Rows are fetched in DataChunks only as
 * the iterator is consumed, and the stream releases the client's query slot
 * when it is drained, closed, or destroyed.
 *
 * Run: php examples/07_streaming.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DuckDB\Quack\QuackClient;

$client = QuackClient::connect(
    'http://127.0.0.1:9494',
    'secret',
    10.0,
    300.0,
);

// range(1, 100000) is far larger than one fetch batch. Nothing but the first
// batch is downloaded until the generator advances.
$stream = $client->query(
    'SELECT range AS n, range * 2 AS doubled FROM range(1, 10001)',
);

echo 'columns: ', implode(', ', array_keys($stream->columns())), PHP_EOL;

$seen = 0;
$sum = 0;
foreach ($stream as $row) {
    $seen++;
    $sum += $row['doubled'];
    if ($seen === 10) {
        break;
    }
}
$stream->close();
echo 'first ', $seen, ' rows, doubled sum: ', $sum, PHP_EOL;

// Column metadata stays on the stream; iterating yields native PHP values.
$stream = $client->query('SELECT * FROM (VALUES (1), (2), (3)) t(n)');
$name = array_key_first($stream->columns()) ?? '';
echo PHP_EOL, 'column: ', $name,
' [', $stream->columns()[$name]->id->name, ']', PHP_EOL;
foreach ($stream as $row) {
    echo '  n = ';
    var_dump($row['n']);
}

// fetchRow() reads one row; close() releases the remaining result early.
$stream = $client->query('SELECT * FROM range(1, 100001)');
$first = $stream->fetchRow();
$stream->close();
echo PHP_EOL, 'fetchRow(): ';
var_dump($first['range'] ?? null);

// Query again; the previous stream's slot has been released.
echo 'next query: ';
var_dump($client->query('SELECT 99 AS ready')->fetchValue('ready'));

echo PHP_EOL, 'done', PHP_EOL;
