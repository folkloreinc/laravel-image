<?php

namespace Folklore\Image\Tests\Unit\Filters;

use Folklore\Image\Facade as Image;
use Folklore\Image\Filters\Resize as ResizeFilter;
use Folklore\Image\Tests\TestCase;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\RGB;

/**
 * @coversDefaultClass Folklore\Image\Filters\Resize
 */
class ResizeTest extends TestCase
{
    protected $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new ResizeFilter;
    }

    /**
     * Test the apply method
     *
     * @test
     *
     * @covers ::apply
     */
    public function test_apply()
    {
        $image = Image::open('image.jpg');

        $thumbnail = $this->filter->apply($image, [
            'width' => 100,
            'height' => 150,
        ]);
        $size = $thumbnail->getSize();
        $this->assertEquals(100, $size->getWidth());
        $this->assertEquals(100, $size->getHeight());
    }

    /**
     * Test the apply method with crop
     *
     * @test
     *
     * @covers ::apply
     */
    public function test_apply_with_crop()
    {
        $image = Image::open('image.jpg');

        $thumbnail = $this->filter->apply($image, '100,150,true');
        $size = $thumbnail->getSize();
        $this->assertEquals(100, $size->getWidth());
        $this->assertEquals(150, $size->getHeight());

        $thumbnail = $this->filter->apply($image, '100,150,top_center');
        $size = $thumbnail->getSize();
        $this->assertEquals(100, $size->getWidth());
        $this->assertEquals(150, $size->getHeight());

        $thumbnail = $this->filter->apply($image, '100,150,bottom_right');
        $size = $thumbnail->getSize();
        $this->assertEquals(100, $size->getWidth());
        $this->assertEquals(150, $size->getHeight());
    }

    /**
     * strip() converts the pixels to the palette's profile, so it runs on the
     * resized image instead of the full-size original
     *
     * @test
     *
     * @covers ::apply
     */
    public function test_apply_strips_after_resizing()
    {
        $this->assertEquals(['resize', 'strip'], $this->callsOnThumbnail(['width' => 400, 'height' => 300]));
        $this->assertEquals(['resize', 'crop', 'strip'], $this->callsOnThumbnail('400,200,true'));
        $this->assertEquals(['strip'], $this->callsOnThumbnail(['width' => 8000, 'height' => 6000]));
    }

    /**
     * Apply the filter on a 4000×3000 image and return the manipulations made on
     * its copy, in order
     */
    protected function callsOnThumbnail($value): array
    {
        $calls = [];
        $thumbnail = $this->createStub(ImageInterface::class);
        $thumbnail->method('getSize')->willReturn(new Box(4000, 3000));
        $thumbnail->method('usePalette')->willReturnSelf();
        foreach (['resize', 'crop', 'strip'] as $method) {
            $thumbnail->method($method)->willReturnCallback(function () use (&$calls, $method, $thumbnail) {
                $calls[] = $method;

                return $thumbnail;
            });
        }

        $image = $this->createStub(ImageInterface::class);
        $image->method('getSize')->willReturn(new Box(4000, 3000));
        $image->method('palette')->willReturn(new RGB);
        $image->method('copy')->willReturn($thumbnail);

        $this->filter->apply($image, $value);

        return $calls;
    }
}
