<?php

namespace DuckDB\Quack\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuackExamplesTest extends TestCase
{
    /**
     * @return string[][]
     */
    public static function exampleFiles(): array
    {
        $files = [];
        $folder = __DIR__ . '/../../examples/';
        foreach (scandir($folder) as $file) {
            if (str_ends_with($file, '.php')) {
                $files[] = [$file];
            }
        }

        return $files;
    }

    #[DataProvider('exampleFiles')]
    public function testExamples(string $file): void
    {
        $folder = __DIR__ . '/../../examples/';
        ob_start();
        require $folder . $file;
        $output = ob_get_contents();
        ob_end_clean();
        self::assertNotEmpty($output);
    }
}
