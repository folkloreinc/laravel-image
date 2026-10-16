<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Variants;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Specs\Format;
use Folklore\Image\Tests\TestCase;
use Folklore\Image\Variants\ImageVariant;

final class ImageVariantTest extends TestCase
{
    public function test_a_variant_serializes_to_the_json_of_apis(): void
    {
        $variant = new ImageVariant('https://img.example.com/img/_/card.3f9a1c,v-8c1f/media/med_01JAB3QX.webp', 800, 450, Format::Webp, 'card');

        $this->assertSame(
            '{"preset":"card","url":"https:\/\/img.example.com\/img\/_\/card.3f9a1c,v-8c1f\/media\/med_01JAB3QX.webp","width":800,"height":450,"mime":"image\/webp"}',
            json_encode($variant),
        );
        $this->assertSame($variant->toArray(), $variant->jsonSerialize());
        $this->assertSame('image/webp', $variant->mime());
        $this->assertNull((new ImageVariant('/img/a.jpg', 10, 10, Format::Jpeg))->preset);
    }

    public function test_a_variant_has_a_concrete_format(): void
    {
        $this->expectException(InvalidImageSpec::class);

        new ImageVariant('/img/a', 10, 10, Format::Auto);
    }
}
