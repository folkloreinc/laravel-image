<?php

declare(strict_types=1);

namespace Folklore\Image\Variants;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An ImageVariant in an API response:
 *
 *     { "preset": "card", "url": "https://img.example.com/img/_/card.3f9a1c,w400,v-8c1f/media/med_01JAB3QX.webp",
 *       "width": 400, "height": 225, "mime": "image/webp" }
 *
 *     return ImageVariantResource::collection(Image::for($media)->variants('card'));
 *
 * @property ImageVariant $resource
 */
final class ImageVariantResource extends JsonResource
{
    public function __construct(ImageVariant $variant)
    {
        parent::__construct($variant);
    }

    /**
     * @return array{preset: string|null, url: string, width: int, height: int, mime: string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
