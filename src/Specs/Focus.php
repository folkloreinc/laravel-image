<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;

/**
 * The point to keep when an image is cropped, from 0 to 1 on each axis, from the top left.
 * It has a precision of 0.01, the precision of URLs.
 */
final readonly class Focus
{
    public float $x;

    public float $y;

    public function __construct(float $x, float $y)
    {
        if ($x < 0 || $x > 1 || $y < 0 || $y > 1) {
            throw new InvalidImageSpec("A focal point is between 0 and 1 on each axis, not ({$x}, {$y}).");
        }

        $this->x = round($x, 2);
        $this->y = round($y, 2);
    }

    /**
     * @param  array<mixed>  $focus  `['x' => 0.3, 'y' => 0.6]`, or `[0.3, 0.6]`
     */
    public static function fromArray(array $focus): self
    {
        $x = $focus['x'] ?? $focus[0] ?? null;
        $y = $focus['y'] ?? $focus[1] ?? null;

        if (! is_int($x) && ! is_float($x) || ! is_int($y) && ! is_float($y)) {
            throw new InvalidImageSpec('A focal point needs a numeric x and y.');
        }

        return new self($x, $y);
    }

    /**
     * @return array{x: float, y: float}
     */
    public function toArray(): array
    {
        return ['x' => $this->x, 'y' => $this->y];
    }
}
