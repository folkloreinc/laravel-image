<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;

/**
 * Dimensions in pixels.
 */
final readonly class Size
{
    public function __construct(
        public int $width,
        public int $height,
    ) {
        if ($width < 1 || $height < 1) {
            throw new InvalidImageSpec("A size must be at least 1×1, not {$width}×{$height}.");
        }
    }

    /**
     * Rounds each dimension, and keeps at least one pixel.
     */
    public static function round(float $width, float $height): self
    {
        return new self(max(1, (int) round($width)), max(1, (int) round($height)));
    }

    public function ratio(): float
    {
        return $this->width / $this->height;
    }

    public function swapped(): self
    {
        return new self($this->height, $this->width);
    }

    public function scaled(float $factor): self
    {
        return self::round($this->width * $factor, $this->height * $factor);
    }

    public function equals(self $other): bool
    {
        return $this->width === $other->width && $this->height === $other->height;
    }
}
