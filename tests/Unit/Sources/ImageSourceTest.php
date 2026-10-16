<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Sources;

use Folklore\Image\Exceptions\InvalidImageSource;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Size;
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
}
