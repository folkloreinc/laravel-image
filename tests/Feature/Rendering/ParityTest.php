<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Rendering;

use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Rendering\InterventionRenderer;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Imagick;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every derivative keeps what 1.1 guarantees (#75), on every driver. The assertions are
 * ported from 1.x's AutoOrientTest, MetadataTest and HeicTest, with the same fixtures:
 *
 * - `orientation/` holds the same 80×40 image in each of the eight EXIF orientations:
 *   upright, its top-left quarter is red, top-right green, bottom-left blue and
 *   bottom-right yellow;
 * - `metadata/gps.jpg` has EXIF with a camera make and GPS coordinates;
 * - `metadata/adobe-rgb.jpg` has an embedded Adobe RGB (1998) profile and four 40×40
 *   patches; the expected sRGB values come from LittleCMS (relative colorimetric);
 * - `heic/photo.heic` is a 120×80 iPhone photo.
 */
final class ParityTest extends TestCase
{
    private const array UPRIGHT = [
        [10, 5, [255, 0, 0]],
        [70, 5, [0, 255, 0]],
        [10, 35, [0, 0, 255]],
        [70, 35, [255, 255, 0]],
    ];

    /** [x, y, stored Adobe RGB value, sRGB value] */
    private const array ADOBE_RGB_PATCHES = [
        [60, 20, [180, 90, 60], [205, 90, 56]],
        [100, 20, [70, 110, 170], [37, 110, 174]],
    ];

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.fixtures', [
            'driver' => 'local',
            'root' => self::fixturePath('images'),
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return array_combine(array_keys(self::DRIVERS), array_map(fn (string $name) => [$name], array_keys(self::DRIVERS)));
    }

    #[DataProvider('drivers')]
    public function test_every_orientation_comes_out_upright(string $driver): void
    {
        foreach (range(1, 8) as $orientation) {
            $image = $this->render($driver, "orientation/orientation-{$orientation}.jpg", new ImageSpec(format: Format::Png));

            $this->assertUpright($image, 80, 40, "Orientation {$orientation}");
        }
    }

    #[DataProvider('drivers')]
    public function test_resizes_apply_to_the_upright_image(string $driver): void
    {
        foreach ([3, 6, 8] as $orientation) {
            $image = $this->render($driver, "orientation/orientation-{$orientation}.jpg", new ImageSpec(width: 40, height: 20, format: Format::Png));

            $this->assertUpright($image, 40, 20, "Orientation {$orientation}");
        }

        // A centered square crop of the upright image: red and green on top.
        $image = $this->render($driver, 'orientation/orientation-6.jpg', new ImageSpec(width: 40, height: 40, format: Format::Png));
        $this->assertSame([40, 40], [$image->width, $image->height]);
        $this->assertColorAt([255, 0, 0], $image, 5, 5, 40);
        $this->assertColorAt([0, 255, 0], $image, 35, 5, 40);
        $this->assertColorAt([0, 0, 255], $image, 5, 35, 40);
        $this->assertColorAt([255, 255, 0], $image, 35, 35, 40);
    }

    #[DataProvider('drivers')]
    public function test_the_output_has_no_orientation_tag(string $driver): void
    {
        $image = $this->render($driver, 'orientation/orientation-6.jpg', new ImageSpec(format: Format::Jpeg));

        $this->assertUpright($image, 80, 40);
        $this->assertContains($this->exif($image)['IFD0']['Orientation'] ?? 1, [0, 1], 'A viewer would rotate the upright image again.');
    }

    #[DataProvider('drivers')]
    public function test_derivatives_have_no_exif(string $driver): void
    {
        foreach ([new ImageSpec(format: Format::Jpeg), new ImageSpec(width: 60, height: 40, format: Format::Jpeg), new ImageSpec(format: Format::Webp)] as $spec) {
            $image = $this->render($driver, 'metadata/gps.jpg', $spec);
            $exif = $image->format === Format::Jpeg ? $this->exif($image) : [];

            $this->assertArrayNotHasKey('GPS', $exif, $spec->key());
            $this->assertArrayNotHasKey('Make', $exif['IFD0'] ?? [], $spec->key());
            $this->assertArrayNotHasKey('ImageDescription', $exif['IFD0'] ?? [], $spec->key());
            $this->assertStringNotContainsString('Exif', $image->contents, $spec->key());
        }
    }

    /**
     * GD has no color management: it keeps the stored values, as in 1.1.
     *
     * @return array<string, array{string}>
     */
    public static function colorManagedDrivers(): array
    {
        return ['imagick' => ['imagick'], 'vips' => ['vips']];
    }

    #[DataProvider('colorManagedDrivers')]
    public function test_colors_are_converted_to_srgb_and_the_profile_removed(string $driver): void
    {
        foreach ([new ImageSpec(format: Format::Png), new ImageSpec(width: 160, height: 40, format: Format::Png), new ImageSpec(format: Format::Jpeg, quality: 100)] as $spec) {
            $image = $this->render($driver, 'metadata/adobe-rgb.jpg', $spec);

            $this->assertFalse($this->hasIccProfile($image), $spec->key());
            foreach (self::ADOBE_RGB_PATCHES as [$x, $y, $stored, $srgb]) {
                $this->assertColorAt($srgb, $image, $x, $y, 6, $spec->key());
            }
        }
    }

    public function test_gd_keeps_the_stored_colors_without_a_profile(): void
    {
        $image = $this->render('gd', 'metadata/adobe-rgb.jpg', new ImageSpec(format: Format::Png));

        $this->assertFalse($this->hasIccProfile($image));
        foreach (self::ADOBE_RGB_PATCHES as [$x, $y, $stored]) {
            $this->assertColorAt($stored, $image, $x, $y, 6);
        }
    }

    /**
     * Reading HEIC needs Imagick built with libheif and its HEVC decoder, or libvips with
     * heifload. CI installs the decoder in the Imagick jobs, where the test can't skip.
     *
     * @return array<string, array{string}>
     */
    public static function heicDrivers(): array
    {
        return ['imagick' => ['imagick'], 'vips' => ['vips']];
    }

    #[DataProvider('heicDrivers')]
    public function test_a_heic_source_is_rendered_as_jpeg_with_the_auto_format(string $driver): void
    {
        $this->skipUnlessHeicIsRead($driver);

        $image = $this->render($driver, 'heic/photo.heic', new ImageSpec(width: 60, height: 40), 'image/heic');

        $this->assertSame(Format::Jpeg, $image->format);
        $info = getimagesizefromstring($image->contents);
        $this->assertNotFalse($info);
        $this->assertSame([60, 40, 'image/jpeg'], [$info[0], $info[1], $info['mime']]);
    }

    #[DataProvider('heicDrivers')]
    public function test_a_heic_source_is_rendered_in_the_format_asked_for(string $driver): void
    {
        $this->skipUnlessHeicIsRead($driver);

        $image = $this->render($driver, 'heic/photo.heic', new ImageSpec(width: 60, format: Format::Png), 'image/heic');

        $info = getimagesizefromstring($image->contents);
        $this->assertNotFalse($info);
        $this->assertSame('image/png', $info['mime']);
    }

    public function test_a_driver_that_cannot_read_heic_fails_to_render(): void
    {
        $this->expectException(RenderFailed::class);

        $this->render('gd', 'heic/photo.heic', new ImageSpec(width: 60), 'image/heic');
    }

    private function render(string $driver, string $path, ImageSpec $spec, string $mime = 'image/jpeg'): RenderedImage
    {
        $filesystems = $this->app?->make(FilesystemFactory::class);
        $this->assertInstanceOf(FilesystemFactory::class, $filesystems);
        $renderer = new InterventionRenderer($filesystems, $this->driverOrSkip($driver));

        return $renderer->render(new ImageSource('disk', $path, disk: 'fixtures', path: $path, mime: $mime), $spec);
    }

    private function skipUnlessHeicIsRead(string $driver): void
    {
        $this->driverOrSkip($driver);
        $required = $driver === 'imagick' && self::driverUnderTest() === 'imagick';

        $reason = match ($driver) {
            'imagick' => Imagick::queryFormats('HEIC') === [] ? 'Imagick has no HEIC support.' : null,
            default => null,
        };
        if ($reason === null) {
            try {
                $this->render($driver, 'heic/photo.heic', new ImageSpec(width: 10), 'image/heic');
            } catch (RenderFailed $exception) {
                $reason = $exception->getMessage();
            }
        }

        if ($reason !== null) {
            $required
                ? $this->fail("HEIC must be read in the Imagick CI job: {$reason}")
                : $this->markTestSkipped("The {$driver} driver can't read HEIC here: {$reason}");
        }
    }

    /**
     * The EXIF sections of a JPEG (`IFD0`, `GPS`…).
     *
     * @return array<string, array<mixed>>
     */
    private function exif(RenderedImage $image): array
    {
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($image->contents), null, true);
        $sections = [];
        foreach (is_array($exif) ? $exif : [] as $name => $section) {
            if (is_string($name) && is_array($section)) {
                $sections[$name] = $section;
            }
        }

        return $sections;
    }

    /**
     * Whether a PNG has an iCCP chunk, or a JPEG an ICC_PROFILE segment.
     */
    private function hasIccProfile(RenderedImage $image): bool
    {
        return str_contains($image->contents, 'iCCP') || str_contains($image->contents, 'ICC_PROFILE');
    }

    private function assertUpright(RenderedImage $image, int $width, int $height, string $message = ''): void
    {
        $this->assertSame([$width, $height], [$image->width, $image->height], $message);

        foreach (self::UPRIGHT as [$x, $y, $expected]) {
            $this->assertColorAt($expected, $image, intdiv($x * $width, 80), intdiv($y * $height, 40), 40, $message);
        }
    }

    /**
     * @param  array<int>  $expected
     */
    private function assertColorAt(array $expected, RenderedImage $image, int $x, int $y, int $delta, string $message = ''): void
    {
        $gd = imagecreatefromstring($image->contents);
        $this->assertNotFalse($gd, 'GD reads the output.');
        $index = imagecolorat($gd, $x, $y);
        $this->assertIsInt($index);
        $color = imagecolorsforindex($gd, $index);
        $actual = [$color['red'], $color['green'], $color['blue']];

        foreach ($expected as $channel => $value) {
            $this->assertEqualsWithDelta($value, $actual[$channel], $delta, trim("{$message} at {$x},{$y}: ".implode(',', $actual)));
        }
    }
}
