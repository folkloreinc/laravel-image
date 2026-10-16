<?php

declare(strict_types=1);

namespace Folklore\Image\Variants;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\Size;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * One rendition of an image, for templates and APIs: its URL, its exact dimensions and its
 * type. The dimensions come from the source's metadata, without rendering anything.
 *
 *     { "preset": "card", "url": "https://img.example.com/img/_/card.3f9a1c,v-8c1f/media/med_01JAB3QX.webp",
 *       "width": 800, "height": 450, "mime": "image/webp" }
 *
 * @implements Arrayable<string, int|string|null>
 */
final readonly class ImageVariant implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $url,
        public int $width,
        public int $height,
        public Format $format,
        public ?string $preset = null,
    ) {
        if ($format === Format::Auto) {
            throw new InvalidImageSpec('A variant has a concrete format.');
        }
        new Size($width, $height);
    }

    public function mime(): string
    {
        return $this->format->mime();
    }

    public function size(): Size
    {
        return new Size($this->width, $this->height);
    }

    /**
     * @return array{preset: string|null, url: string, width: int, height: int, mime: string}
     */
    public function toArray(): array
    {
        return [
            'preset' => $this->preset,
            'url' => $this->url,
            'width' => $this->width,
            'height' => $this->height,
            'mime' => $this->mime(),
        ];
    }

    /**
     * @return array{preset: string|null, url: string, width: int, height: int, mime: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
