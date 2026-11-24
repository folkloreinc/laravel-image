<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Rendering;

use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Crop;
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
        $source = new ImageSource('disk', 'quarters.jpg', disk: 'large', path: 'quarters.jpg', mime: 'image/jpeg');

        $whole = $this->renderer()->render($source, new ImageSpec(width: 160, format: Format::Png));
        $this->assertSame([160, 100], $this->dimensions($whole));
        $this->assertColorAt(self::RED, $whole, 20, 20);
        $this->assertColorAt(self::GREEN, $whole, 140, 20);
        $this->assertColorAt(self::BLUE, $whole, 20, 80);
        $this->assertColorAt(self::YELLOW, $whole, 140, 80);

        // The 1000 × 1000 square at the right.
        $focused = $this->renderer()->render($source, new ImageSpec(width: 100, height: 100, focus: new Focus(1, 0.5), format: Format::Png));
        $this->assertSame([100, 100], $this->dimensions($focused));
        $this->assertColorAt(self::RED, $focused, 10, 20);
        $this->assertColorAt(self::GREEN, $focused, 90, 20);
        $this->assertColorAt(self::YELLOW, $focused, 90, 80);

        // The bottom right quarter, with 100 px of the bottom left one.
        $cropped = $this->renderer()->render($source, new ImageSpec(transform: new Transform(new Crop(700, 500, 900, 500)), width: 90, format: Format::Png));
        $this->assertSame([90, 50], $this->dimensions($cropped));
        $this->assertColorAt(self::BLUE, $cropped, 4, 25);
        $this->assertColorAt(self::YELLOW, $cropped, 16, 25);
        $this->assertColorAt(self::YELLOW, $cropped, 85, 45);
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
