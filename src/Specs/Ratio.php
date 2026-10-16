<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;

/**
 * An aspect ratio, as a reduced fraction: `32:18` is `16:9`.
 */
final readonly class Ratio
{
    public int $width;

    public int $height;

    public function __construct(int $width, int $height)
    {
        if ($width < 1 || $height < 1) {
            throw new InvalidImageSpec("Invalid ratio: {$width}:{$height}.");
        }

        $divisor = self::gcd($width, $height);
        $this->width = intdiv($width, $divisor);
        $this->height = intdiv($height, $divisor);
    }

    /**
     * @param  string  $ratio  `16:9` or `16/9`
     */
    public static function fromString(string $ratio): self
    {
        if (preg_match('/^(\d+)\s*[:\/]\s*(\d+)$/', $ratio, $matches) !== 1) {
            throw new InvalidImageSpec("Invalid ratio: {$ratio}. Use the width:height form, such as 16:9.");
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public function value(): float
    {
        return $this->width / $this->height;
    }

    public function toString(): string
    {
        return "{$this->width}:{$this->height}";
    }

    private static function gcd(int $a, int $b): int
    {
        return $b === 0 ? $a : self::gcd($b, $a % $b);
    }
}
