<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Rendering;

use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Crop;
use Folklore\Image\Specs\Flip;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Transform;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Jcupitt\Vips\FFI as VipsFFI;
use Jcupitt\Vips\Image as VipsImage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class VipsRendererTest extends InterventionRendererTestCase
{
    protected static function driver(): string
    {
        return 'vips';
    }

    /**
     * A large original cropped to a small cover is decoded at a fraction of its size: libvips
     * never allocates its decoded pixels (4000 × 1000 × 4 bands, 16 MB), which it did before,
     * when the crop came before the resize. In a process of its own, since libvips counts its
     * largest allocation since the process started.
     */
    #[RunInSeparateProcess]
    public function test_a_large_jpeg_is_decoded_at_a_fraction_of_its_size(): void
    {
        $image = $this->renderer()->render(
            $this->source('metadata/adobe-rgb-large.jpg'),
            new ImageSpec(width: 100, height: 100, focus: new Focus(0.2, 0.5), format: Format::Jpeg),
        );

        $this->assertSame([100, 100], $this->dimensions($image));
        $this->assertLessThan(12 * 1024 ** 2, $this->vips('vips_tracked_get_mem_highwater'));
    }

    public function test_the_renderer_turns_off_the_libvips_operation_cache(): void
    {
        $this->renderer()->render($this->landscapeSource(), new ImageSpec(width: 100, format: Format::Png));

        $this->assertSame(0, $this->vips('vips_cache_get_max'));
        $this->assertSame(0, $this->vips('vips_cache_get_size'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function disks(): iterable
    {
        yield 'a local disk, read in place' => ['local'];
        yield 'a remote disk, read in memory' => ['remote'];
    }

    /**
     * A JPEG stored on its side (EXIF orientation 6), upright 1600 × 1000 in four quarters,
     * rendered at a small size: decoded at an eighth, it is still turned upright, cropped
     * around the focal point, and its transform's crop is scaled to it.
     */
    #[DataProvider('disks')]
    public function test_a_shrunk_original_is_upright_and_cropped_like_the_original(string $disk): void
    {
        $renderer = $this->renderer();
        $source = $this->quarters($disk);

        $whole = $renderer->render($source, new ImageSpec(width: 160, format: Format::Png));
        $this->assertSame([160, 100], $this->dimensions($whole));
        $this->assertColorAt(self::RED, $whole, 20, 20);
        $this->assertColorAt(self::GREEN, $whole, 140, 20);
        $this->assertColorAt(self::BLUE, $whole, 20, 80);
        $this->assertColorAt(self::YELLOW, $whole, 140, 80);

        // The 1000 × 1000 square at the right.
        $focused = $renderer->render($source, new ImageSpec(width: 100, height: 100, focus: new Focus(1, 0.5), format: Format::Png));
        $this->assertSame([100, 100], $this->dimensions($focused));
        $this->assertColorAt(self::RED, $focused, 10, 20);
        $this->assertColorAt(self::GREEN, $focused, 90, 20);
        $this->assertColorAt(self::YELLOW, $focused, 90, 80);

        // The bottom right quarter, with 100 px of the bottom left one.
        $cropped = $renderer->render($source, new ImageSpec(transform: new Transform(new Crop(700, 500, 900, 500)), width: 90, format: Format::Png));
        $this->assertSame([90, 50], $this->dimensions($cropped));
        $this->assertColorAt(self::BLUE, $cropped, 4, 25);
        $this->assertColorAt(self::YELLOW, $cropped, 16, 25);
        $this->assertColorAt(self::YELLOW, $cropped, 85, 45);
    }

    /**
     * @return iterable<string, array{string, Transform, array{int, int}, array<array{array<int>, int, int}>}>
     */
    public static function turns(): iterable
    {
        $cases = [
            // The upright quarters: red, green / blue, yellow.
            'rotate 90' => [new Transform(rotate: 90), [100, 160], [[self::BLUE, 20, 20], [self::RED, 80, 20], [self::YELLOW, 20, 140], [self::GREEN, 80, 140]]],
            'rotate 180' => [new Transform(rotate: 180), [160, 100], [[self::YELLOW, 20, 20], [self::BLUE, 140, 20], [self::GREEN, 20, 80], [self::RED, 140, 80]]],
            'rotate 270' => [new Transform(rotate: 270), [100, 160], [[self::GREEN, 20, 20], [self::YELLOW, 80, 20], [self::RED, 20, 140], [self::BLUE, 80, 140]]],
            'flip horizontal' => [new Transform(flip: Flip::Horizontal), [160, 100], [[self::GREEN, 20, 20], [self::RED, 140, 20], [self::YELLOW, 20, 80], [self::BLUE, 140, 80]]],
            'flip vertical' => [new Transform(flip: Flip::Vertical), [160, 100], [[self::BLUE, 20, 20], [self::YELLOW, 140, 20], [self::RED, 20, 80], [self::GREEN, 140, 80]]],
            'flip both' => [new Transform(flip: Flip::Both), [160, 100], [[self::YELLOW, 20, 20], [self::BLUE, 140, 20], [self::GREEN, 20, 80], [self::RED, 140, 80]]],
            // The right half, green above yellow, turned: yellow at the left.
            'crop and rotate 90' => [new Transform(new Crop(800, 0, 800, 1000), 90), [100, 80], [[self::YELLOW, 20, 40], [self::GREEN, 80, 40]]],
            // The bottom right quarter with 100 px of blue at its left, mirrored: blue at the right.
            'crop, rotate 180 and flip vertical' => [new Transform(new Crop(700, 500, 900, 500), 180, Flip::Vertical), [90, 50], [[self::YELLOW, 40, 25], [self::BLUE, 86, 25]]],
        ];
        foreach (self::disks() as $disk => [$name]) {
            foreach ($cases as $case => $arguments) {
                yield "{$case}, {$disk}" => [$name, ...$arguments];
            }
        }
    }

    /**
     * Rotations and flips of a shrunk original, which libvips reads in one pass from the top.
     *
     * @param  array{int, int}  $dimensions
     * @param  array<array{array<int>, int, int}>  $colors
     */
    #[DataProvider('turns')]
    public function test_a_shrunk_original_turns_and_flips_like_the_original(string $disk, Transform $transform, array $dimensions, array $colors): void
    {
        $renderer = $this->renderer();
        $width = $dimensions[0];

        $image = $renderer->render($this->quarters($disk), new ImageSpec(transform: $transform, width: $width, format: Format::Png));

        $this->assertSame($dimensions, $this->dimensions($image));
        foreach ($colors as [$color, $x, $y]) {
            $this->assertColorAt($color, $image, $x, $y);
        }
    }

    /**
     * A JPEG stored on its side (EXIF orientation 6), upright 1600 × 1000 in four quarters:
     * red at the top left, green at the top right, blue at the bottom left and yellow at the
     * bottom right. On a local disk, or on one read in memory.
     */
    private function quarters(string $disk): ImageSource
    {
        $upright = VipsImage::black(800, 500)->add([255, 0, 0])
            ->insert(VipsImage::black(800, 500)->add([0, 255, 0]), 800, 0, ['expand' => true])
            ->insert(VipsImage::black(800, 500)->add([0, 0, 255]), 0, 500, ['expand' => true])
            ->insert(VipsImage::black(800, 500)->add([255, 255, 0]), 800, 500, ['expand' => true])
            ->cast('uchar')
            ->copy(['interpretation' => 'srgb']);
        // Orientation 6 displays the stored image turned a quarter clockwise.
        $stored = $upright->rot270();
        $stored->set('orientation', 6);
        $contents = $stored->jpegsave_buffer(['Q' => 95]);
        if ($disk === 'local') {
            Storage::fake('large')->put('quarters.jpg', $contents);
        } else {
            // A disk that isn't local: the renderer reads the file in memory.
            $remote = Mockery::mock(Filesystem::class);
            $remote->allows('get')->with('quarters.jpg')->andReturn($contents);
            Storage::set('large', $remote);
        }

        return new ImageSource('disk', 'quarters.jpg', disk: 'large', path: 'quarters.jpg', mime: 'image/jpeg');
    }

    /**
     * One of libvips' counters, through FFI.
     */
    private function vips(string $function): int
    {
        $value = VipsFFI::vips()->{$function}();
        $this->assertIsInt($value);

        return $value;
    }
}
