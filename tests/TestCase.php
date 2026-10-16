<?php

declare(strict_types=1);

namespace Folklore\Image\Tests;

use Folklore\Image\ImageServiceProvider;
use Illuminate\Support\ServiceProvider;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Drivers\Vips\Driver as VipsDriver;
use Intervention\Image\Exceptions\MissingDependencyException;
use Intervention\Image\Interfaces\DriverInterface;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The Intervention drivers, by the names of the `image.renderer.driver` config.
     *
     * @var array<string, class-string<DriverInterface>>
     */
    final protected const array DRIVERS = [
        'gd' => GdDriver::class,
        'imagick' => ImagickDriver::class,
        'vips' => VipsDriver::class,
    ];

    /**
     * @return list<class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ImageServiceProvider::class,
        ];
    }

    /**
     * The driver CI runs this job for (IMAGE_TEST_DRIVER), or null to test every
     * driver available on this machine.
     */
    protected static function driverUnderTest(): ?string
    {
        $driver = getenv('IMAGE_TEST_DRIVER');

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /**
     * An Intervention driver, or skips the test when it isn't available here. The
     * driver under test is never skipped: the test fails instead, so a CI job can't
     * silently test less than its name says.
     */
    protected function driverOrSkip(string $name): DriverInterface
    {
        $class = self::DRIVERS[$name] ?? null;
        $this->assertNotNull($class, "Unknown driver: {$name}");

        try {
            return new $class;
        } catch (MissingDependencyException $exception) {
            if (static::driverUnderTest() === $name) {
                $this->fail("The {$name} driver is under test but not available: {$exception->getMessage()}");
            }

            $this->markTestSkipped("The {$name} driver is not available: {$exception->getMessage()}");
        }
    }

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
