<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Urls;

use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Tests\TestCase;
use Folklore\Image\Urls\ImageUrl;
use Folklore\Image\Urls\PresetReference;
use Folklore\Image\Urls\V2Codec;

final class ImageUrlTest extends TestCase
{
    public function test_a_url_for_a_source_takes_its_version_and_resolves_the_auto_format(): void
    {
        $source = new ImageSource('media', 'med_01JAB3QX', '8c1f', width: 1200, height: 800, mime: 'image/png');
        $codec = new V2Codec;

        $this->assertSame('_/w300,v-8c1f/media/med_01JAB3QX.png', $codec->encode(ImageUrl::forSource($source, new ImageSpec(width: 300))));
        $this->assertSame('_/w300,v-8c1f/media/med_01JAB3QX.webp', $codec->encode(ImageUrl::forSource($source, new ImageSpec(width: 300), Format::Webp)));
        $this->assertSame('_/card.3f9a1c,w400,v-8c1f/media/med_01JAB3QX.png', $codec->encode(ImageUrl::forSource($source, new PresetReference('card', '3f9a1c'), width: 400)));
    }

    public function test_a_url_has_a_concrete_format(): void
    {
        $this->expectException(InvalidImageUrl::class);

        ImageUrl::forSpec('disk', 'a.jpg', new ImageSpec(width: 300));
    }

    public function test_a_url_has_a_valid_key(): void
    {
        $this->expectException(InvalidImageUrl::class);

        ImageUrl::forSpec('disk', '../a.jpg', new ImageSpec(width: 300, format: Format::Jpeg));
    }
}
