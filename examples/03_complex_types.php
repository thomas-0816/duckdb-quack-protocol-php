<?php

/**
 * Complex logical types.
 *
 * MAP, LIST, STRUCT, ENUM, UNION, VARIANT, DECIMAL, HUGEINT, and UUID are
 * decoded into native PHP arrays and scalars. Values outside PHP's integer
 * range stay as exact strings.
 *
 * Run: php examples/03_complex_types.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DuckDB\Quack\QuackClient;

$client = QuackClient::connect(
    'http://127.0.0.1:9494',
    'secret',
    10.0,
    300.0,
);

$sql = <<<'SQL'
    SELECT
        MAP {'a': 1, 'b': 2}                         AS map_value,
        [1, 2, 3]                                    AS list_value,
        {'name': 'Ada', 'age': 36}                   AS struct_value,
        CAST('happy' AS ENUM ('sad', 'ok', 'happy')) AS enum_value,
        CAST(42 AS UNION(answer INTEGER, label VARCHAR)) AS union_value,
        CAST(1 AS VARIANT)                           AS variant_value,
        CAST('12.34' AS DECIMAL(10,2))               AS decimal_value,
        170141183460469231731687303715884105727::HUGEINT AS huge_value,
        uuid()                                       AS uuid_value
    SQL;

$row = $client->query($sql)->fetchRow();

foreach ($row as $name => $value) {
    echo $name, ' [', get_debug_type($value), '] = ';
    var_dump($value);
}

echo PHP_EOL,
'INT, HUGEINT, and decimal values outside PHP\'s int range stay exact.',
PHP_EOL;

echo PHP_EOL, 'same row as JSON:', PHP_EOL;
echo json_encode(
    $row,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
), PHP_EOL;
