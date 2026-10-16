<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;
use Intervention\Image\Format;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Checks that each image driver CI installs really works, before any renderer
 * uses it: CI runs every job with IMAGE_TEST_DRIVER set to its driver
 * (docs/decisions/0002-rendering-engine.md).
 */
final class DriversTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return array_map(fn (string $class) => [array_search($class, self::DRIVERS, true)], self::DRIVERS);
    }

    public function test_the_driver_under_test_is_known(): void
    {
        $driver = self::driverUnderTest();

        if ($driver === null) {
            $this->markTestSkipped('IMAGE_TEST_DRIVER is not set: every available driver is tested.');
        }

        $this->assertArrayHasKey($driver, self::DRIVERS);
    }

    #[DataProvider('drivers')]
    public function test_the_driver_reads_and_writes_images(string $name): void
    {
        $manager = new ImageManager($this->driverOrSkip($name));

        $image = $manager->decodePath(self::fixturePath('images/image.jpg'));
        $width = $image->width();
        $height = $image->height();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);

        foreach ([Format::JPEG, Format::PNG, Format::WEBP] as $format) {
            $encoded = $manager->decodePath(self::fixturePath('images/image.jpg'))
                ->scale(width: 100)
                ->encodeUsingFormat($format);

            $decoded = $manager->decodeBinary((string) $encoded);
            $this->assertSame(100, $decoded->width(), "{$name}: {$format->name}");
            $this->assertSame((int) round($height * 100 / $width), $decoded->height(), "{$name}: {$format->name}");
        }
    }
}
