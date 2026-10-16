<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;

/**
 * A rectangle of the source, in pixels of the upright original, from the top left.
 */
final readonly class Crop
{
    public function __construct(
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
        if ($x < 0 || $y < 0 || $width < 1 || $height < 1) {
            throw new InvalidImageSpec("Invalid crop: {$width}×{$height} at ({$x}, {$y}).");
        }
    }

    /**
     * @param  array<mixed>  $crop  `['x' => 0, 'y' => 0, 'width' => 800, 'height' => 600]`
     */
    public static function fromArray(array $crop): self
    {
        $values = [];
        foreach (['x', 'y', 'width', 'height'] as $key) {
            $value = $crop[$key] ?? null;
            if (! is_int($value)) {
                throw new InvalidImageSpec("A crop needs an integer {$key}.");
            }
            $values[] = $value;
        }

        return new self(...$values);
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y, 'width' => $this->width, 'height' => $this->height];
    }
}
