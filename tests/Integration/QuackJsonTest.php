<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\Exceptions\ServerException;
use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackJsonTest extends TestCase
{
    public function testJsonValuesDecode(): void
    {
        self::assertSame(
            [['CAST(\'[1, null, {"key": "value"}]\' AS "JSON")' => [1, null, ['key' => 'value']]]],
            QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10)->fetchAll('SELECT \'[1, null, {"key": "value"}]\'::JSON'),
        );
    }

    public function testMalformedJsonIsRejected(): void
    {
        try {
            QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10)->fetchAll("SELECT 'unquoted'::JSON");
            self::fail('expected malformed JSON to be rejected');
        } catch (ServerException $error) {
            self::assertStringContainsString('Malformed JSON at byte 0 of input: unexpected character.', $error->getMessage());
            self::assertStringContainsString('Input: "unquoted"', $error->getMessage());
        }
    }

    public function testJsonCasts(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [['CAST(CAST(\'{  "a":5 }\' AS "JSON") AS VARCHAR)' => '{  "a":5 }']],
            $client->fetchAll('SELECT \'{  "a":5 }\'::JSON::VARCHAR'),
        );

        self::assertSame(
            [['CAST(\'{"a":1,"a":2}\' AS "JSON")' => ['a' => 2]]],
            $client->fetchAll('SELECT \'{"a":1,"a":2}\'::JSON'),
        );

        self::assertSame(
            [['CAST(CAST(\'{"duck": 42}\' AS "JSON") AS STRUCT(duck INTEGER))' => ['duck' => 42]]],
            $client->fetchAll('SELECT \'{"duck": 42}\'::JSON::STRUCT(duck INTEGER)'),
        );

        self::assertSame(
            [['CAST(struct_pack(duck := 42) AS "JSON")' => ['duck' => 42]]],
            $client->fetchAll('SELECT {duck: 42}::JSON'),
        );

        self::assertSame(
            [['CAST(CAST(\'2023-05-12\' AS DATE) AS "JSON")' => '2023-05-12']],
            $client->fetchAll("SELECT '2023-05-12'::DATE::JSON"),
        );
    }

    public function testErrorsAsJson(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        try {
            $client->execute('SET errors_as_json = true; SELECT foo FROM bar');
            self::fail('expected the missing table to be rejected');
        } catch (ServerException $error) {
            self::assertStringContainsString('{"exception_type":"Catalog"', $error->getMessage());
            self::assertStringContainsString('Table with name bar does not exist!', $error->getMessage());
        } finally {
            $client->execute('SET errors_as_json = false');
        }
    }

    public function testReadRemoteJson(): void
    {
        $url = 'https://bulk.meteostat.net/v2/stations/lite.json.gz';

        self::assertSame(
            [['id' => '10381', 'en' => 'Berlin / Dahlem'], ['id' => '10382', 'en' => 'Berlin / Tegel']],
            QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10)->fetchAll(
                "select id, name.en from read_json('{$url}') WHERE name.en like '%Berlin%' limit 2",
            ),
        );
    }

    public function testReadJsonFileAndCopyToParquet(): void
    {
        $suffix = bin2hex(random_bytes(8));
        $jsonFile = sys_get_temp_dir() . "/quack_logs_{$suffix}.json";
        $parquetFile = sys_get_temp_dir() . "/quack_logs_{$suffix}.parquet";

        file_put_contents($jsonFile, json_encode(['log' => 'log text']) . PHP_EOL);
        file_put_contents($jsonFile, json_encode(['log' => 'log text 2']) . PHP_EOL, FILE_APPEND);

        try {
            $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

            self::assertSame(
                [['log' => 'log text'], ['log' => 'log text 2']],
                $client->fetchAll("SELECT * FROM '{$jsonFile}'"),
            );
            $client->execute("COPY (SELECT * FROM '{$jsonFile}') TO '{$parquetFile}' (COMPRESSION zstd)");
            self::assertSame(['log' => 'log text'], $client->fetchOne("SELECT * FROM '{$parquetFile}'"));
        } finally {
            @unlink($jsonFile);
            @unlink($parquetFile);
        }
    }

    public function testHttpBearerToken(): void
    {
        $url = 'https://mockhttp.org/headers';
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $client->execute("CREATE OR REPLACE SECRET http_auth (TYPE http, SCOPE '{$url}', BEARER_TOKEN 'some secret')");
        $client->execute('SET force_download=true');

        self::assertSame('Bearer some secret', $client->fetchValue("SELECT headers.authorization FROM read_json('{$url}')"));
    }

    public function testJsonRoundTrip(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        $json = '{"a":42,"a":-0,"\u0000":1.20e+03,"s":"\ud83d\ude00\n","x":[],"y":{},"z":[null,true,false]}';
        self::assertSame(
            '{"a":0,"\u0000":1200,"s":"\ud83d\ude00\n","x":[],"y":[],"z":[null,true,false]}',
            json_encode($client->fetchValue("select '{$json}'::json"), JSON_THROW_ON_ERROR),
        );
    }

    public function testNonFiniteJsonAndVariant(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);

        self::assertSame(
            [[
                'CAST(CAST(\'NaN\' AS DOUBLE) AS "json")' => 'NaN',
                'CAST(CAST(\'Infinity\' AS DOUBLE) AS "json")' => 'Infinity',
                'CAST(CAST(\'-Infinity\' AS DOUBLE) AS "json")' => '-Infinity',
            ]],
            $client->fetchAll("SELECT 'NaN'::DOUBLE::json,'Infinity'::DOUBLE::json,'-Infinity'::DOUBLE::json"),
        );

        $row = $client->fetchOne("SELECT 'NaN'::DOUBLE::variant,'Infinity'::DOUBLE::variant,'-Infinity'::DOUBLE::variant");
        self::assertIsArray($row);
        self::assertNan($row['CAST(CAST(\'NaN\' AS DOUBLE) AS VARIANT)']);
        self::assertSame(INF, $row['CAST(CAST(\'Infinity\' AS DOUBLE) AS VARIANT)']);
        self::assertSame(-INF, $row['CAST(CAST(\'-Infinity\' AS DOUBLE) AS VARIANT)']);
    }
}
