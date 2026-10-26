<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Rendering;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Facades\Image;
use Folklore\Image\Rendering\InterventionRenderer;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Crop;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Flip;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Ratio;
use Folklore\Image\Specs\Transform;
use Folklore\Image\Testing\Contracts\RendererContractTests;
use Folklore\Image\Tests\Doubles\InvertOperation;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The InterventionRenderer on one driver. Each driver has its own test case, so a CI job
 * tests its driver and skips the others.
 *
 * `landscape.jpg` is 800×500, in four quarters: red at the top left, green at the top
 * right, blue at the bottom left and yellow at the bottom right.
 */
abstract class InterventionRendererTestCase extends TestCase
{
    use RendererContractTests;

    protected const array RED = [255, 0, 0];

    protected const array GREEN = [0, 255, 0];

    protected const array BLUE = [0, 0, 255];

    protected const array YELLOW = [255, 255, 0];

    /**
     * The driver under test: `gd`, `imagick` or `vips`.
     */
    abstract protected static function driver(): string;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.fixtures', [
            'driver' => 'local',
            'root' => self::fixturePath('images'),
        ]);
    }

    /**
     * @param  array<string, int>  $quality
     * @param  array<string, Operation>  $operations
     */
    protected function renderer(array $quality = [], bool $upscale = false, ?string $memoryLimit = null, array $operations = []): InterventionRenderer
    {
        return new InterventionRenderer(
            $this->filesystems(),
            $this->driverOrSkip(static::driver()),
            quality: $quality,
            upscale: $upscale,
            memoryLimit: $memoryLimit,
            operations: $operations,
        );
    }

    protected function landscapeSource(): ImageSource
    {
        return $this->source('landscape.jpg', 800, 500);
    }

    protected function source(string $path, ?int $width = null, ?int $height = null, ?string $mime = 'image/jpeg'): ImageSource
    {
        return new ImageSource('disk', $path, disk: 'fixtures', path: $path, width: $width, height: $height, mime: $mime);
    }

    protected function filesystems(): FilesystemFactory
    {
        $filesystems = $this->app?->make(FilesystemFactory::class);
        $this->assertInstanceOf(FilesystemFactory::class, $filesystems);

        return $filesystems;
    }

    public function test_the_renderer_uses_its_driver(): void
    {
        $this->assertSame(static::driver(), $this->renderer()->driverName());
    }

    public function test_cover_crops_around_the_center_by_default(): void
    {
        $image = $this->render(new ImageSpec(width: 200, height: 200, format: Format::Png));

        // The middle 500×500 square: the four quarters are still there.
        $this->assertSame([200, 200], [$image->width, $image->height]);
        $this->assertColorAt(self::RED, $image, 20, 20);
        $this->assertColorAt(self::GREEN, $image, 180, 20);
        $this->assertColorAt(self::BLUE, $image, 20, 180);
        $this->assertColorAt(self::YELLOW, $image, 180, 180);
    }

    public function test_cover_crops_around_the_focal_point_of_the_spec(): void
    {
        $image = $this->render(new ImageSpec(width: 100, height: 100, focus: new Focus(0, 0), format: Format::Png));

        // The 500×500 square at the left: red above blue.
        $this->assertColorAt(self::RED, $image, 50, 20);
        $this->assertColorAt(self::BLUE, $image, 50, 80);
    }

    public function test_cover_uses_the_focal_point_of_the_source_when_the_spec_has_none(): void
    {
        $source = new ImageSource('disk', 'landscape.jpg', disk: 'fixtures', path: 'landscape.jpg', width: 800, height: 500, mime: 'image/jpeg', focalPoint: new Focus(1, 0.5));

        $image = $this->renderer()->render($source, new ImageSpec(width: 100, height: 100, format: Format::Png));

        $this->assertColorAt(self::GREEN, $image, 50, 20);
        $this->assertColorAt(self::YELLOW, $image, 50, 80);
    }

    public function test_contain_pads_the_image_to_the_box(): void
    {
        $image = $this->render(new ImageSpec(width: 200, height: 200, fit: Fit::Contain, format: Format::Png));

        // 200×125, centered: a band of 37 or 38 pixels above and below.
        $this->assertSame([200, 200], [$image->width, $image->height]);
        $this->assertTransparentAt($image, 100, 10);
        $this->assertColorAt(self::RED, $image, 10, 50);
        $this->assertColorAt(self::YELLOW, $image, 190, 150);
        $this->assertTransparentAt($image, 100, 190);
    }

    public function test_contain_pads_with_white_in_jpeg(): void
    {
        $image = $this->render(new ImageSpec(width: 200, height: 200, fit: Fit::Contain, format: Format::Jpeg));

        $this->assertColorAt([255, 255, 255], $image, 100, 10, 10);
        $this->assertColorAt(self::RED, $image, 10, 50);
    }

    public function test_inside_keeps_the_whole_image_without_padding(): void
    {
        $image = $this->render(new ImageSpec(width: 200, height: 200, fit: Fit::Inside, format: Format::Png));

        $this->assertSame([200, 125], [$image->width, $image->height]);
        $this->assertColorAt(self::RED, $image, 10, 10);
        $this->assertColorAt(self::YELLOW, $image, 190, 115);
    }

    public function test_fill_distorts_the_image_to_the_box(): void
    {
        $image = $this->render(new ImageSpec(width: 100, height: 200, fit: Fit::Fill, format: Format::Png));

        $this->assertSame([100, 200], [$image->width, $image->height]);
        $this->assertColorAt(self::RED, $image, 10, 10);
        $this->assertColorAt(self::GREEN, $image, 90, 10);
        $this->assertColorAt(self::BLUE, $image, 10, 190);
        $this->assertColorAt(self::YELLOW, $image, 90, 190);
    }

    public function test_a_ratio_alone_crops_the_source(): void
    {
        $image = $this->render(new ImageSpec(ratio: new Ratio(1, 1), format: Format::Png));

        $this->assertSame([500, 500], [$image->width, $image->height]);
    }

    public function test_images_are_not_upscaled_by_default(): void
    {
        $this->assertSame([800, 500], $this->dimensions($this->render(new ImageSpec(width: 1600))));
        $this->assertSame([800, 400], $this->dimensions($this->render(new ImageSpec(width: 1600, height: 800))));
    }

    public function test_images_are_upscaled_when_allowed(): void
    {
        $image = $this->renderer(upscale: true)->render($this->landscapeSource(), new ImageSpec(width: 1600));

        $this->assertSame([1600, 1000], $this->dimensions($image));
    }

    public function test_a_transform_crops_rotates_and_flips_the_source(): void
    {
        // The top right quarter, rotated clockwise then flipped vertically: green, 250×400.
        $spec = new ImageSpec(transform: new Transform(new Crop(400, 0, 400, 250), 90, Flip::Vertical), format: Format::Png);

        $image = $this->render($spec);

        $this->assertSame([250, 400], $this->dimensions($image));
        $this->assertColorAt(self::GREEN, $image, 125, 200);
    }

    public function test_the_rotation_is_clockwise(): void
    {
        $image = $this->render(new ImageSpec(transform: new Transform(rotate: 90), format: Format::Png));

        // The left column (red above blue) becomes the top row, blue on the left.
        $this->assertSame([500, 800], $this->dimensions($image));
        $this->assertColorAt(self::BLUE, $image, 20, 20);
        $this->assertColorAt(self::RED, $image, 480, 20);
    }

    public function test_the_transform_of_the_source_applies_when_the_spec_has_none(): void
    {
        $source = new ImageSource('disk', 'landscape.jpg', disk: 'fixtures', path: 'landscape.jpg', width: 800, height: 500, mime: 'image/jpeg', transform: new Transform(new Crop(0, 250, 400, 250)));

        $fromSource = $this->renderer()->render($source, new ImageSpec(format: Format::Png));
        $fromSpec = $this->renderer()->render($source, new ImageSpec(transform: new Transform(new Crop(400, 0, 400, 250)), format: Format::Png));

        $this->assertColorAt(self::BLUE, $fromSource, 200, 125);
        $this->assertColorAt(self::GREEN, $fromSpec, 200, 125);
    }

    public function test_the_focal_point_of_the_source_follows_its_rotation(): void
    {
        // The red corner, at the top left of the upright original, is at the top right once
        // rotated: a tall strip keeps red above green, not blue above yellow.
        $source = new ImageSource('disk', 'landscape.jpg', disk: 'fixtures', path: 'landscape.jpg', width: 800, height: 500, mime: 'image/jpeg', transform: Transform::fromArray([
            'rotate' => 90,
            'focalPoint' => ['x' => 0, 'y' => 0],
        ]));

        $image = $this->renderer()->render($source, new ImageSpec(width: 20, height: 100, format: Format::Png));

        $this->assertSame([20, 100], $this->dimensions($image));
        $this->assertColorAt(self::RED, $image, 10, 20);
        $this->assertColorAt(self::GREEN, $image, 10, 80);
    }

    public function test_the_focus_of_the_spec_follows_its_flip(): void
    {
        // The left edge of the upright original is the right edge once flipped: red above blue.
        $spec = new ImageSpec(transform: new Transform(flip: Flip::Horizontal), width: 20, height: 100, focus: new Focus(0, 0.5), format: Format::Png);

        $image = $this->render($spec);

        $this->assertColorAt(self::RED, $image, 10, 20);
        $this->assertColorAt(self::BLUE, $image, 10, 80);
    }

    public function test_the_focal_point_follows_the_crop(): void
    {
        // 320 px from the left of the original is 20 px from the left of the crop: red above
        // blue. Read relative to the crop, 0.4 would give green above yellow.
        $spec = new ImageSpec(transform: new Transform(new Crop(300, 0, 500, 500)), width: 20, height: 100, focus: new Focus(0.4, 0.5), format: Format::Png);

        $image = $this->render($spec);

        $this->assertColorAt(self::RED, $image, 10, 20);
        $this->assertColorAt(self::BLUE, $image, 10, 80);
    }

    /**
     * The media's own edits (on a crop of the original) and edits for one use, each rotation and
     * each flip on both sides.
     *
     * @return array<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function compositions(): array
    {
        $edits = [
            'nothing' => [],
            '90°' => ['rotate' => 90],
            '180°' => ['rotate' => 180],
            '270°' => ['rotate' => 270],
            'flipX' => ['flipX' => true],
            'flipY' => ['flipY' => true],
        ];

        $cases = [];
        foreach ($edits as $mediaName => $media) {
            foreach ($edits as $usageName => $usage) {
                $cases["media {$mediaName}, use {$usageName}"] = [['crop' => ['x' => 100, 'y' => 50, 'width' => 600, 'height' => 400], ...$media], $usage];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $usage
     */
    #[DataProvider('compositions')]
    public function test_a_transform_over_the_media_s_renders_as_both_one_after_the_other(array $media, array $usage): void
    {
        $source = $this->landscapeSource();
        $edited = new ImageSource('disk', 'landscape.jpg', disk: 'fixtures', path: 'landscape.jpg', width: 800, height: 500, mime: 'image/jpeg', transform: Transform::fromArray($media));

        // The media's version, stored as a file, then the edits for this use on it.
        $version = $this->renderer()->render($source, new ImageSpec(transform: Transform::fromArray($media), format: Format::Png));
        Storage::fake('versions')->put('version.png', $version->contents);
        $crop = ['x' => intdiv($version->width, 10), 'y' => intdiv($version->height, 5), 'width' => intdiv($version->width * 3, 5), 'height' => intdiv($version->height, 2)];
        $expected = $this->renderer()->render(
            new ImageSource('disk', 'version.png', disk: 'versions', path: 'version.png', width: $version->width, height: $version->height, mime: 'image/png'),
            new ImageSpec(transform: Transform::fromArray(['crop' => $crop, ...$usage]), format: Format::Png),
        );

        $spec = Image::for($edited)->transform(['base' => 'media', 'crop' => $crop, ...$usage])->png()->spec();
        $composed = $this->renderer()->render($edited, $spec);

        $this->assertSame($this->dimensions($expected), $this->dimensions($composed), $spec->key());
        foreach ([[0.15, 0.15], [0.85, 0.15], [0.15, 0.85], [0.85, 0.85], [0.5, 0.5]] as [$x, $y]) {
            $at = [(int) ($x * $composed->width), (int) ($y * $composed->height)];
            $this->assertColorAt(array_slice($this->rgbaAt($expected, ...$at), 0, 3), $composed, ...$at);
        }
    }

    public function test_a_transform_for_one_use_replaces_the_media_s_without_a_base(): void
    {
        // A sideways original, turned upright by the media; the crop of the original's top left.
        $edited = new ImageSource('disk', 'landscape.jpg', disk: 'fixtures', path: 'landscape.jpg', width: 800, height: 500, mime: 'image/jpeg', transform: new Transform(rotate: 90));

        $replaced = $this->renderer()->render($edited, Image::for($edited)->transform(['crop' => ['x' => 0, 'y' => 0, 'width' => 400, 'height' => 250]])->png()->spec());
        $over = $this->renderer()->render($edited, Image::for($edited)->transform(['base' => 'media', 'crop' => ['x' => 0, 'y' => 0, 'width' => 250, 'height' => 400]])->png()->spec());
        $original = $this->renderer()->render($edited, Image::for($edited)->transform(['focalPoint' => ['x' => 0.5, 'y' => 0.5]])->png()->spec());

        $this->assertSame([400, 250], $this->dimensions($replaced));
        $this->assertColorAt(self::RED, $replaced, 200, 125);
        // The top left of the upright version is the bottom left of the original.
        $this->assertSame([250, 400], $this->dimensions($over));
        $this->assertColorAt(self::BLUE, $over, 125, 200);
        // Without edits of its own, the original as it is.
        $this->assertSame([800, 500], $this->dimensions($original));
        $this->assertColorAt(self::RED, $original, 20, 20);
    }

    public function test_the_focal_point_of_a_transform_over_the_media_s_stays_on_its_subject(): void
    {
        // The top left of the upright version is blue: a tall strip around it is blue above yellow.
        $edited = new ImageSource('disk', 'landscape.jpg', disk: 'fixtures', path: 'landscape.jpg', width: 800, height: 500, mime: 'image/jpeg', transform: new Transform(rotate: 90));

        $spec = Image::for($edited)->transform(['base' => 'media', 'focalPoint' => ['x' => 0, 'y' => 0]])->size(20, 100)->png()->spec();
        $image = $this->renderer()->render($edited, $spec);

        $this->assertColorAt(self::BLUE, $image, 10, 20);
        $this->assertColorAt(self::YELLOW, $image, 10, 80);
    }

    public function test_a_crop_over_the_media_s_is_clamped_to_its_version(): void
    {
        $edited = new ImageSource('disk', 'landscape.jpg', disk: 'fixtures', path: 'landscape.jpg', width: 800, height: 500, mime: 'image/jpeg', transform: new Transform(rotate: 90));

        $clamped = $this->renderer()->render($edited, Image::for($edited)->transform(['base' => 'media', 'crop' => ['x' => 400, 'y' => 700, 'width' => 300, 'height' => 300]])->png()->spec());
        $outside = $this->renderer()->render($edited, Image::for($edited)->transform(['base' => 'media', 'crop' => ['x' => 600, 'y' => 0, 'width' => 10, 'height' => 10]])->png()->spec());

        // The bottom right 100×100 of the upright version: green, at the top right of the original.
        $this->assertSame([100, 100], $this->dimensions($clamped));
        $this->assertColorAt(self::GREEN, $clamped, 50, 50);
        $this->assertSame([500, 800], $this->dimensions($outside));
    }

    public function test_the_quality_comes_from_the_spec_then_the_config(): void
    {
        $low = $this->renderer(quality: ['jpeg' => 10])->render($this->source('image.jpg'), new ImageSpec(format: Format::Jpeg));
        $high = $this->renderer(quality: ['jpeg' => 10])->render($this->source('image.jpg'), new ImageSpec(format: Format::Jpeg, quality: 95));
        $default = $this->renderer()->render($this->source('image.jpg'), new ImageSpec(format: Format::Jpeg));

        $this->assertLessThan($default->length(), $low->length());
        $this->assertGreaterThan($default->length(), $high->length());
    }

    public function test_a_png_source_stays_png_with_the_auto_format(): void
    {
        $image = $this->renderer()->render($this->source('image.png', mime: 'image/png'), new ImageSpec(width: 50));

        $this->assertSame(Format::Png, $image->format);
        $this->assertSame([50, 50], $this->dimensions($image));
    }

    public function test_it_writes_avif_when_the_driver_can(): void
    {
        try {
            $image = $this->render(new ImageSpec(width: 100, format: Format::Avif));
        } catch (RenderFailed $exception) {
            $this->markTestSkipped('The '.static::driver()." driver can't write AVIF here: {$exception->getMessage()}");
        }

        $this->assertSame('image/avif', $image->mime());
        $this->assertStringContainsString('ftypavif', substr($image->contents, 0, 32));
    }

    public function test_operations_apply_by_name(): void
    {
        $image = $this->renderer(operations: ['invert' => new InvertOperation])
            ->render($this->landscapeSource(), new ImageSpec(width: 200, operations: ['invert'], format: Format::Png));

        // Red inverted is cyan.
        $this->assertColorAt([0, 255, 255], $image, 10, 10);
    }

    public function test_an_unknown_operation_is_refused(): void
    {
        $this->expectException(InvalidImageSpec::class);

        $this->render(new ImageSpec(operations: ['invert']));
    }

    public function test_a_missing_original_is_not_found(): void
    {
        $this->expectException(SourceNotFound::class);

        $this->renderer()->render($this->source('missing.jpg'), new ImageSpec(width: 100));
    }

    public function test_a_source_without_a_disk_is_not_found(): void
    {
        $this->expectException(SourceNotFound::class);

        $this->renderer()->render(new ImageSource('media', 'med_1'), new ImageSpec(width: 100));
    }

    public function test_a_file_that_is_not_an_image_fails_to_render(): void
    {
        $this->expectException(RenderFailed::class);

        $this->renderer()->render($this->source('wrong.jpg'), new ImageSpec(width: 100));
    }

    public function test_the_memory_limit_is_raised_but_never_lowered(): void
    {
        $previous = (string) ini_get('memory_limit');

        try {
            ini_set('memory_limit', '200M');
            $this->renderer(memoryLimit: '300M')->render($this->landscapeSource(), new ImageSpec(width: 10));
            $this->assertSame('300M', ini_get('memory_limit'));

            $this->renderer(memoryLimit: '100M')->render($this->landscapeSource(), new ImageSpec(width: 10));
            $this->assertSame('300M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $previous);
        }
    }

    protected function render(ImageSpec $spec): RenderedImage
    {
        return $this->renderer()->render($this->landscapeSource(), $spec);
    }

    /**
     * @return array{int, int}
     */
    protected function dimensions(RenderedImage $image): array
    {
        return [$image->width, $image->height];
    }

    /**
     * @return array{int, int, int, int} red, green, blue and alpha (0 opaque, 127 transparent), read with GD
     */
    protected function rgbaAt(RenderedImage $image, int $x, int $y): array
    {
        $gd = imagecreatefromstring($image->contents);
        $this->assertNotFalse($gd, 'GD reads the output.');
        $index = imagecolorat($gd, $x, $y);
        $this->assertIsInt($index, "GD reads the pixel at {$x},{$y}.");
        $color = imagecolorsforindex($gd, $index);

        return [$color['red'], $color['green'], $color['blue'], $color['alpha']];
    }

    /**
     * @param  array<int>  $expected
     */
    protected function assertColorAt(array $expected, RenderedImage $image, int $x, int $y, int $delta = 40): void
    {
        $actual = $this->rgbaAt($image, $x, $y);

        foreach ($expected as $channel => $value) {
            $this->assertEqualsWithDelta($value, $actual[$channel], $delta, "At {$x},{$y}: ".implode(',', $actual));
        }
    }

    protected function assertTransparentAt(RenderedImage $image, int $x, int $y): void
    {
        $this->assertSame(127, $this->rgbaAt($image, $x, $y)[3], "At {$x},{$y}");
    }
}
