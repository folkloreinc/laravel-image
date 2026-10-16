<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Facades\Image;
use Folklore\Image\Variants\ImageVariantResource;
use Illuminate\Http\Request;

final class ImageVariantResourceTest extends ImageTestCase
{
    public function test_a_variant_in_an_api_response(): void
    {
        $variant = Image::for($this->photo)->preset('og')->variant();

        $this->assertSame(
            ['preset' => 'og', 'url' => $variant->url, 'width' => 1200, 'height' => 630, 'mime' => 'image/jpeg'],
            (new ImageVariantResource($variant))->toArray(new Request),
        );
    }

    public function test_variants_as_a_collection(): void
    {
        $response = ImageVariantResource::collection(Image::for($this->photo)->variants('card'))->toResponse(new Request);
        $data = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($data);
        $this->assertIsArray($data['data']);
        $this->assertCount(9, $data['data']);
        $this->assertSame(['preset', 'url', 'width', 'height', 'mime'], array_keys((array) $data['data'][0]));
        $this->assertSame('image/avif', ((array) $data['data'][0])['mime']);
    }
}
