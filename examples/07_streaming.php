<?php

/**
 * Streaming results.
 *
 * fetchIterator() returns a lazy generator. Rows are fetched in DataChunks only
 * as the iterator is consumed, and the stream releases the client's query slot
 * when it is drained or destroyed.
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
$stream = $client->fetchIterator(
    'SELECT range AS n, range * 2 AS doubled FROM range(1, 10001)',
);

$seen = 0;
$sum = 0;
foreach ($stream as $row) {
    if ($seen === 0) {
        echo 'columns: ', implode(', ', array_keys($row)), PHP_EOL;
    }
    $seen++;
    $sum += $row['doubled'];
    if ($seen === 10) {
        break;
    }
}
unset($stream);
echo 'first ', $seen, ' rows, doubled sum: ', $sum, PHP_EOL;

// Iterating yields native PHP values.
foreach ($client->fetchIterator('SELECT * FROM (VALUES (1), (2), (3)) t(n)') as $row) {
    echo '  n = ';
    var_dump($row['n']);
}

// fetchOne() reads one row and releases the remaining result early.
$first = $client->fetchOne('SELECT * FROM range(1, 100001)') ?? [];
echo PHP_EOL, 'fetchOne(): ';
var_dump($first['range'] ?? null);

// Query again; the previous stream's slot has been released.
echo 'next query: ';
var_dump($client->fetchValue('SELECT 99 AS ready'));

echo PHP_EOL, 'done', PHP_EOL;
