<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Rendering;

use Folklore\Image\Rendering\ShrinkOnLoad;
use Folklore\Image\Specs\Crop;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Size;
use Folklore\Image\Specs\Transform;
use Folklore\Image\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ShrinkOnLoadTest extends TestCase
{
    /**
     * @return iterable<string, array{ImageSpec, int, bool}>
     */
    public static function specs(): iterable
    {
        // A 10,000 × 5,625 original, a large one: the output only needs to fit.
        yield 'inside 3200: halved' => [new ImageSpec(width: 3200, height: 3200, fit: Fit::Inside), 2, false];
        yield 'inside 1200: by 8' => [new ImageSpec(width: 1200, height: 1200, fit: Fit::Inside), 8, false];
        yield 'a width of 1250: by 8, exactly' => [new ImageSpec(width: 1250), 8, false];
        yield 'a width of 1251: by 4' => [new ImageSpec(width: 1251), 4, false];
        yield 'cover 1200×630: the height binds' => [new ImageSpec(width: 1200, height: 630), 8, false];
        yield 'cover 1600×1600: the height binds' => [new ImageSpec(width: 1600, height: 1600), 2, false];
        yield 'the original size: not shrunk' => [new ImageSpec, 1, false];
        yield 'more than half: not shrunk' => [new ImageSpec(width: 5001), 1, false];
        yield 'an upscale: not shrunk' => [new ImageSpec(width: 20000), 1, true];
        yield 'a crop: relative to the crop' => [new ImageSpec(transform: new Transform(new Crop(0, 0, 2000, 2000)), width: 1000), 2, false];
        yield 'a crop at its own size: not shrunk' => [new ImageSpec(transform: new Transform(new Crop(0, 0, 2000, 2000))), 1, false];
        yield 'a quarter turn' => [new ImageSpec(transform: new Transform(rotate: 90), width: 1400), 4, false];
    }

    #[DataProvider('specs')]
    public function test_a_large_original_is_shrunk_as_long_as_the_output_fits(ImageSpec $spec, int $factor, bool $upscale): void
    {
        $this->assertSame($factor, ShrinkOnLoad::factor(new Size(10000, 5625), $spec, $upscale));
    }

    /**
     * @return iterable<string, array{ImageSpec, int}>
     */
    public static function smallerSpecs(): iterable
    {
        // A 4032 × 3024 original (12 megapixels): the resize after the shrink does 2× or more.
        yield 'a width of 1008: halved, a 2× resize left' => [new ImageSpec(width: 1008), 2];
        yield 'a width of 1009: not shrunk' => [new ImageSpec(width: 1009), 1];
        yield 'a width of 504: by 4' => [new ImageSpec(width: 504), 4];
        yield 'a width of 252: by 8' => [new ImageSpec(width: 252), 8];
        yield 'cover 800×800: the height binds' => [new ImageSpec(width: 800, height: 800), 1];
        yield 'cover 700×700: the height binds' => [new ImageSpec(width: 700, height: 700), 2];
        yield 'a crop: relative to the crop' => [new ImageSpec(transform: new Transform(new Crop(0, 0, 2000, 2000)), width: 250), 4];
    }

    #[DataProvider('smallerSpecs')]
    public function test_a_smaller_original_leaves_the_resize_at_least_2x(ImageSpec $spec, int $factor): void
    {
        $this->assertSame($factor, ShrinkOnLoad::factor(new Size(4032, 3024), $spec, false));
    }

    public function test_an_original_is_large_above_24_megapixels(): void
    {
        // 6000 × 4000 is 24 megapixels: at a width of 2000, halving would leave a 1.5× resize.
        $spec = new ImageSpec(width: 2000);
        $height = intdiv(ShrinkOnLoad::LARGE_ORIGINAL, 6000);
        $this->assertSame(1, ShrinkOnLoad::factor(new Size(6000, $height), $spec, false));
        $this->assertSame(2, ShrinkOnLoad::factor(new Size(6000, $height + 1), $spec, false));
    }
}
