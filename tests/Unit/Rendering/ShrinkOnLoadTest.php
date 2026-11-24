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
        // A 10,000 × 5,625 original.
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
    public function test_the_factor_keeps_the_rendered_part_at_least_as_large_as_the_output(ImageSpec $spec, int $factor, bool $upscale): void
    {
        $this->assertSame($factor, ShrinkOnLoad::factor(new Size(10000, 5625), $spec, $upscale));
    }
}
