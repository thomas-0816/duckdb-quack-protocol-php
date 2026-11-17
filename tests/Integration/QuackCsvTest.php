<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackCsvTest extends TestCase
{
    public function testReadCsvFile(): void
    {
        $csvFile = $this->writeCsv([
            ['aaa', 'bbb', 'ccc'],
            ['123', '456', '789'],
            ['aaa', 'bbb', 'ccc'],
        ]);

        try {
            self::assertSame(
                [
                    ['aaa' => '123', 'bbb' => '456', 'ccc' => '789'],
                    ['aaa' => 'aaa', 'bbb' => 'bbb', 'ccc' => 'ccc'],
                ],
                QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10)->fetchAll("SELECT * FROM '{$csvFile}'"),
            );
        } finally {
            unlink($csvFile);
        }
    }

    public function testReadRemoteGzipCsv(): void
    {
        $url = 'https://data.meteostat.net/hourly/2026/10381.csv.gz';

        self::assertSame(
            [
                ['hour' => 10, 'temp' => 24.1],
                ['hour' => 11, 'temp' => 25.5],
                ['hour' => 12, 'temp' => 26.4],
                ['hour' => 13, 'temp' => 27.4],
            ],
            QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 30)->fetchAll(
                "select hour, temp from read_csv('{$url}') where year = 2026 and month = 7 and day = 25 and hour > 9 limit 4",
            ),
        );
    }

    public function testCreateTableFromCsvAndInsert(): void
    {
        $csvFile = $this->writeCsv([
            ['aaa', 'bbb'],
            ['123', '456'],
            ['aaa', 'bbb'],
        ]);

        try {
            $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
            $client->execute("CREATE TEMP TABLE test_csv AS SELECT * FROM read_csv('{$csvFile}')");
            $client->execute("INSERT INTO test_csv SELECT * FROM '{$csvFile}'");

            self::assertSame(
                [
                    ['aaa' => '123', 'bbb' => '456'],
                    ['aaa' => 'aaa', 'bbb' => 'bbb'],
                    ['aaa' => '123', 'bbb' => '456'],
                    ['aaa' => 'aaa', 'bbb' => 'bbb'],
                ],
                $client->fetchAll('SELECT * FROM test_csv'),
            );

            self::assertSame(
                [
                    [
                        'column_name' => 'aaa',
                        'column_type' => 'VARCHAR',
                        'null' => 'YES',
                        'key' => null,
                        'default' => null,
                        'extra' => null,
                    ],
                    [
                        'column_name' => 'bbb',
                        'column_type' => 'VARCHAR',
                        'null' => 'YES',
                        'key' => null,
                        'default' => null,
                        'extra' => null,
                    ],
                ],
                $client->fetchAll('DESCRIBE test_csv'),
            );
        } finally {
            unlink($csvFile);
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private function writeCsv(array $rows): string
    {
        $path = sys_get_temp_dir() . '/quack_csv_' . bin2hex(random_bytes(8)) . '.csv';
        $handle = fopen($path, 'w');
        if ($handle === false) {
            self::fail("could not open {$path} for writing");
        }
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '');
        }
        fclose($handle);

        return $path;
    }
}
