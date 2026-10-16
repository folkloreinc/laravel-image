<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\Size;

/**
 * The output of a renderer: encoded contents, in a concrete format, with their dimensions.
 */
final readonly class RenderedImage
{
    public function __construct(
        public string $contents,
        public Format $format,
        public int $width,
        public int $height,
    ) {
        if ($format === Format::Auto) {
            throw new InvalidImageSpec('A rendered image has a concrete format.');
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
     * The length of the contents, in bytes.
     */
    public function length(): int
    {
        return strlen($this->contents);
    }
}
