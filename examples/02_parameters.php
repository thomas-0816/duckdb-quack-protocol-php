<?php

/**
 * SQL parameters and automatic type detection.
 *
 * Parameters are rendered client-side as DuckDB literals, so placeholders in
 * string literals, quoted identifiers, and comments are left alone.
 *
 * Raw PHP values are detected automatically:
 *   null -> NULL, bool -> TRUE/FALSE, int -> integer, float -> double,
 *   string -> VARCHAR, sequential array -> LIST, string-keyed array -> STRUCT.
 *
 * Run: php examples/02_parameters.php
 */

require __DIR__ . '/../vendor/autoload.php';

use DuckDB\Quack\QuackClient;
use DuckDB\Quack\Sql\SqlFormatter;
use DuckDB\Quack\Sql\SqlParameter;

$client = QuackClient::connect(
    'http://127.0.0.1:9494',
    'secret',
    10.0,
    300.0,
);

// Scalars, lists, and structs are detected without wrapping them in Value.
$params = [41, "can't", [1, 2, 3], ['name' => 'Ada', 'tags' => ['math', 'code']]];
$sql = SqlFormatter::formatSql(
    'SELECT ? + 1 AS answer, ? AS label, ? AS items, ? AS person, '
    . "'? stays' AS untouched",
    $params,
);
echo 'formatted positional SQL:', PHP_EOL, $sql, PHP_EOL, PHP_EOL;

$row = $client->fetchOne(
    'SELECT ? + 1 AS answer, ? AS label, ? AS items',
    [41, "can't", [1, 2, 3]],
) ?? [];
echo 'answer: ';
var_dump($row['answer']);
echo 'label:  ';
var_dump($row['label']);
echo 'items:  ';
print_r($row['items']);

// Named parameters use :name placeholders. A string-keyed array selects the
// named form automatically.
$named = ['id' => 7, 'label' => 'seven'];
echo PHP_EOL, 'formatted named SQL:', PHP_EOL,
SqlFormatter::formatSql('SELECT :id AS id, :label AS label', $named), PHP_EOL;

$row = $client->fetchOne(
    'SELECT :id AS id, :label AS label',
    $named,
) ?? [];
echo 'id:    ';
var_dump($row['id']);
echo 'label: ';
var_dump($row['label']);

// Lists are useful for membership tests.
echo PHP_EOL, 'list membership:', PHP_EOL,
SqlFormatter::formatSql(
    'SELECT * FROM (VALUES (1), (2), (3), (4)) t(x) WHERE list_contains(?, x)',
    [[1, 2, 3]],
),
PHP_EOL;

$rows = $client->fetchColumn(
    'SELECT * FROM (VALUES (1), (2), (3), (4)) t(x) WHERE list_contains(?, x) ORDER BY x',
    [[1, 2, 3]],
);
echo 'matching values: ';
print_r($rows);

// The explicit SqlParameter/SqlParameters API is still available when a caller
// needs to force a specific representation.
$explicit = \DuckDB\Quack\Sql\SqlParameters::fromArray([
    \DuckDB\Quack\Sql\SqlParameter::value(\DuckDB\Quack\Types\Value::string("O'Reilly")),
]);
echo PHP_EOL, 'explicit parameter: ',
SqlFormatter::formatSql('SELECT ? AS author', $explicit), PHP_EOL;

echo 'scalar literal: ',
SqlFormatter::sqlLiteral(SqlParameter::fromPhp("O'Reilly")), PHP_EOL;

try {
    SqlFormatter::formatSql('SELECT ?', []);
} catch (\DuckDB\Quack\Exceptions\ProtocolException $error) {
    echo 'expected error: ', $error->getMessage(), PHP_EOL;
}

echo PHP_EOL, 'done', PHP_EOL;
