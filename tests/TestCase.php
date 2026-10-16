<?php

namespace Folklore\Image\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The path of a file in tests/fixtures.
     */
    protected static function fixturePath(string $path): string
    {
        return __DIR__.'/fixtures/'.$path;
    }

    /**
     * The decoded contents of a JSON file in tests/fixtures.
     *
     * @return array<mixed>
     */
    protected static function jsonFixture(string $path): array
    {
        $contents = file_get_contents(static::fixturePath($path));
        static::assertIsString($contents, $path);

        $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        static::assertIsArray($data, $path);

        return $data;
    }
}
