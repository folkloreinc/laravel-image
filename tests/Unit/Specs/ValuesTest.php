<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Specs\Crop;
use Folklore\Image\Specs\Flip;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\Ratio;
use Folklore\Image\Specs\Size;
use Folklore\Image\Specs\Transform;
use Folklore\Image\Tests\TestCase;

/**
 * The values specs are made of.
 */
final class ValuesTest extends TestCase
{
    public function test_formats_have_one_extension_and_a_mime_type(): void
    {
        $this->assertSame('jpg', Format::Jpeg->extension());
        $this->assertSame('image/jpeg', Format::Jpeg->mime());
        $this->assertSame('image/avif', Format::Avif->mime());
        $this->assertSame(Format::Jpeg, Format::tryFromExtension('jpg'));
        $this->assertNull(Format::tryFromExtension('jpeg'), 'One extension per format, so URLs have one spelling.');
        $this->assertNull(Format::tryFromExtension('auto'));
        $this->assertSame(Format::Webp, Format::tryFromMime('image/webp'));
    }

    public function test_the_auto_format_follows_the_source_when_browsers_display_it(): void
    {
        $this->assertSame(Format::Png, Format::Auto->resolve('image/png'));
        $this->assertSame(Format::Gif, Format::Auto->resolve('image/gif'));
        $this->assertSame(Format::Jpeg, Format::Auto->resolve('image/heic'));
        $this->assertSame(Format::Jpeg, Format::Auto->resolve(null));
        $this->assertSame(Format::Webp, Format::Webp->resolve('image/png'));
    }

    public function test_the_auto_format_has_no_extension(): void
    {
        $this->expectException(InvalidImageSpec::class);

        Format::Auto->extension();
    }

    public function test_ratios_are_reduced(): void
    {
        $this->assertSame('16:9', Ratio::fromString('1920:1080')->toString());
        $this->assertSame('4:5', Ratio::fromString('4 / 5')->toString());
        $this->assertEqualsWithDelta(1.7778, (new Ratio(16, 9))->value(), 0.0001);
    }

    public function test_focal_points_have_a_precision_of_a_percent(): void
    {
        $focus = Focus::fromArray([0.333, 0.6666]);

        $this->assertSame(['x' => 0.33, 'y' => 0.67], $focus->toArray());
        $this->assertSame(['x' => 0.5, 'y' => 0.0], Focus::fromArray(['x' => 0.5, 'y' => 0])->toArray());
    }

    public function test_transforms_read_the_spec_form(): void
    {
        $transform = Transform::fromArray([
            'crop' => ['x' => 10, 'y' => 20, 'width' => 300, 'height' => 200],
            'rotate' => -90,
            'flip' => 'vertical',
        ]);

        $this->assertEquals(new Crop(10, 20, 300, 200), $transform->crop);
        $this->assertSame(270, $transform->rotate);
        $this->assertSame(Flip::Vertical, $transform->flip);
        $this->assertSame(['crop' => ['x' => 10, 'y' => 20, 'width' => 300, 'height' => 200], 'rotate' => 270, 'flip' => 'vertical'], $transform->toArray());
        $this->assertTrue(Transform::fromArray([])->isEmpty());
    }

    public function test_sizes_round_and_keep_at_least_a_pixel(): void
    {
        $this->assertEquals(new Size(267, 1), Size::round(266.5, 0.2));
        $this->assertEquals(new Size(150, 100), (new Size(300, 200))->scaled(0.5));
        $this->assertEquals(new Size(200, 300), (new Size(300, 200))->swapped());
    }

    public function test_sizes_are_positive(): void
    {
        $this->expectException(InvalidImageSpec::class);

        new Size(0, 100);
    }
}
