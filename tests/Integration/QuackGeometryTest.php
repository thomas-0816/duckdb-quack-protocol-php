<?php

namespace DuckDB\Quack\Tests\Integration;

use DuckDB\Quack\QuackClient;
use PHPUnit\Framework\TestCase;

final class QuackGeometryTest extends TestCase
{
    public function testGeometryValues(): void
    {
        $client = QuackClient::connect('http://127.0.0.1:9494', 'secret', 3, 10);
        $client->execute('CREATE TEMP TABLE geometries (id INTEGER, geom GEOMETRY)');
        $client->execute(
            "INSERT INTO geometries VALUES
              (1, 'POINT (30 10)'),
              (2, 'LINESTRING (30 10, 10 30, 40 40)'),
              (3, 'POLYGON ((30 10, 40 40, 20 40, 10 20, 30 10))'),
              (4, 'MULTIPOINT ((10 40), (40 30), (20 20), (30 10))'),
              (5, 'MULTILINESTRING ((10 10, 20 20, 10 40), (40 40, 30 30, 40 20))'),
              (6, 'MULTIPOLYGON (((30 20, 45 40, 10 40, 30 20)), ((15 5, 40 10, 10 20, 5 10,15 5)))'),
              (7, 'GEOMETRYCOLLECTION (POINT(40 10), LINESTRING(10 10,20 20,10 40), POLYGON((40 40,20 45,45 30,40 40)))')",
        );

        self::assertSame(
            [
                ['geom' => 'POINT (30 10)'],
                ['geom' => 'LINESTRING (30 10, 10 30, 40 40)'],
                ['geom' => 'POLYGON ((30 10, 40 40, 20 40, 10 20, 30 10))'],
                ['geom' => 'MULTIPOINT (10 40, 40 30, 20 20, 30 10)'],
                ['geom' => 'MULTILINESTRING ((10 10, 20 20, 10 40), (40 40, 30 30, 40 20))'],
                ['geom' => 'MULTIPOLYGON (((30 20, 45 40, 10 40, 30 20)), ((15 5, 40 10, 10 20, 5 10, 15 5)))'],
                [
                    'geom' => 'GEOMETRYCOLLECTION (POINT (40 10), LINESTRING (10 10, 20 20, 10 40), '
                        . 'POLYGON ((40 40, 20 45, 45 30, 40 40)))',
                ],
            ],
            $client->fetchAll('SELECT geom FROM geometries'),
        );
    }
}
