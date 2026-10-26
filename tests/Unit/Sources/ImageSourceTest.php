<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Sources;

use Folklore\Image\Exceptions\InvalidImageSource;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Crop;
use Folklore\Image\Specs\Flip;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Size;
use Folklore\Image\Specs\Transform;
use Folklore\Image\Tests\TestCase;

final class ImageSourceTest extends TestCase
{
    public function test_a_source_carries_its_metadata(): void
    {
        $source = new ImageSource('media', 'med_01JAB3QX', '8c1f', 's3', 'media/01JAB3QX.jpg', 1200, 800, 'image/jpeg', private: true);

        $this->assertEquals(new Size(1200, 800), $source->size());
        $this->assertTrue($source->isPrivate());
        $this->assertNull((new ImageSource('disk', 'photo.jpg'))->size());
    }

    public function test_keys_are_paths_that_stay_inside_the_source(): void
    {
        foreach (['photo.jpg', 'uploads/2026/photo (1).jpg', 'med_01JAB3QX', 'été/photo.jpg', '.hidden/a.jpg', 'a..b.jpg'] as $key) {
            $this->assertTrue(ImageSource::isValidKey($key), $key);
        }
        foreach (['', '/photo.jpg', 'photo.jpg/', 'a//b.jpg', '../a.jpg', 'a/../b.jpg', './a.jpg', 'a\\b.jpg', "a\0.jpg", "a\n.jpg", str_repeat('a', 1025)] as $key) {
            $this->assertFalse(ImageSource::isValidKey($key), $key);
        }
    }

    public function test_an_invalid_source_is_refused(): void
    {
        foreach ([
            fn () => new ImageSource('Disk', 'a.jpg'),
            fn () => new ImageSource('disk', '../a.jpg'),
            fn () => new ImageSource('disk', 'a.jpg', 'a version'),
            fn () => new ImageSource('disk', 'a.jpg', width: 100),
            fn () => new ImageSource('disk', 'a.jpg', width: 0, height: 100),
            fn () => new ImageSource('disk', 'a.jpg', orientation: 9),
        ] as $index => $make) {
            try {
                $make();
                $this->fail("Source {$index} was accepted.");
            } catch (InvalidImageSource) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_focal_point_of_the_transform_is_the_source_s(): void
    {
        $source = new ImageSource('media', 'med_01JAB3QX', '8c1f02ab', transform: Transform::fromArray([
            'crop' => ['x' => 0, 'y' => 0, 'width' => 400, 'height' => 300],
            'flipX' => true,
            'focalPoint' => ['x' => 0.3, 'y' => 0.6],
        ]));

        $this->assertEquals(new Focus(0.3, 0.6), $source->focalPoint);
        $this->assertEquals(new Transform(new Crop(0, 0, 400, 300), flip: Flip::Horizontal), $source->transform);
        $this->assertNull((new ImageSource('disk', 'photo.jpg', transform: new Transform(focalPoint: new Focus(0.3, 0.6))))->transform);
        $this->assertEquals(new Focus(0.1, 0.2), (new ImageSource('disk', 'photo.jpg', focalPoint: new Focus(0.1, 0.2), transform: new Transform(focalPoint: new Focus(0.3, 0.6))))->focalPoint);
    }

    public function test_the_url_version_changes_with_the_edits(): void
    {
        $source = fn (?Transform $transform = null, ?Focus $focalPoint = null, ?string $version = '8c1f02ab') => new ImageSource('media', 'med_01JAB3QX', $version, transform: $transform, focalPoint: $focalPoint);
        $crop = new Transform(new Crop(0, 0, 400, 300));

        $this->assertSame('8c1f02ab', $source()->urlVersion());
        $this->assertMatchesRegularExpression('/^8c1f02ab-[0-9a-f]{6}$/', (string) $source($crop)->urlVersion());

        $versions = [
            $source($crop)->urlVersion(),
            $source(new Transform(new Crop(0, 0, 400, 301)))->urlVersion(),
            $source(new Transform(rotate: 90))->urlVersion(),
            $source(new Transform(flip: Flip::Vertical))->urlVersion(),
            $source(focalPoint: new Focus(0.3, 0.6))->urlVersion(),
            $source($crop, new Focus(0.3, 0.6))->urlVersion(),
            $source($crop, new Focus(0.3, 0.61))->urlVersion(),
        ];
        $this->assertSame($versions, array_values(array_unique($versions)), 'Each edit gives another version.');
        $this->assertSame($source($crop)->urlVersion(), $source(new Transform(new Crop(0, 0, 400, 300)))->urlVersion());
        $this->assertSame($source($crop, new Focus(0.3, 0.6))->urlVersion(), $source(new Transform(new Crop(0, 0, 400, 300), focalPoint: new Focus(0.3, 0.6)))->urlVersion());

        $this->assertNull($source($crop, version: null)->urlVersion());
        $long = (string) $source($crop, version: str_repeat('a', 64))->urlVersion();
        $this->assertTrue(ImageSource::isValidVersion($long));
        $this->assertNotSame($long, $source($crop, version: str_repeat('a', 63).'b')->urlVersion());
    }
}
