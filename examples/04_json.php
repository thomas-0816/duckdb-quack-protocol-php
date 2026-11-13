<?php

/**
 * JSON output.
 *
 * Query rows contain native PHP values, so they can be passed straight to
 * json_encode(). Integers outside PHP's range stay as strings, blobs are
 * binary strings, and temporal values are readable strings.
 *
 * JsonConverter is still available when working with raw decoded Value
 * objects, for example from a DataChunk.
 *
 * Run: php examples/04_json.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DuckDB\Quack\QuackClient;

$client = QuackClient::connect(
    'http://127.0.0.1:9494',
    'secret',
    10.0,
    300.0,
);

$row = $client->query(
    <<<'SQL'
        SELECT
            9223372036854775807::BIGINT  AS big,
            'hi'::BLOB                   AS blob,
            CAST('12.34' AS DECIMAL(10,2)) AS decimal_value,
            DATE '1970-01-02'            AS date_value,
            TIMESTAMP '1970-01-01 00:00:01' AS timestamp_value,
            [1, NULL]                    AS list_value
        SQL,
)->one();

echo 'native row as JSON:', PHP_EOL;
echo json_encode($row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;

$huge = $client->query(
    'SELECT 170141183460469231731687303715884105727::HUGEINT AS n',
)->one()['n'];
echo PHP_EOL, 'huge integer: ';
var_dump($huge);
