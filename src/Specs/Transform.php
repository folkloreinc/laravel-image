<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;

/**
 * Non-destructive edits, applied to the upright original before anything else, in this
 * order: crop (in source pixels), rotate (clockwise), flip. Panneau 5 stores them on a media
 * or on a field value (docs/decisions/0006-sources-and-resolvers.md).
 */
final readonly class Transform
{
    /** Clockwise, in degrees: 0, 90, 180 or 270. */
    public int $rotate;

    public function __construct(
        public ?Crop $crop = null,
        int $rotate = 0,
        public ?Flip $flip = null,
    ) {
        $normalized = (($rotate % 360) + 360) % 360;
        if ($normalized % 90 !== 0) {
            throw new InvalidImageSpec("Rotations are multiples of 90 degrees, not {$rotate}.");
        }

        $this->rotate = $normalized;
    }

    /**
     * Panneau 5's shape: `['crop' => ['x' => …, 'y' => …, 'width' => …, 'height' => …], 'rotate' => 90,
     * 'flip' => 'horizontal']`. Other keys, such as `focalPoint`, are left to the resolver.
     *
     * @param  array<mixed>  $transform
     */
    public static function fromArray(array $transform): self
    {
        $crop = $transform['crop'] ?? null;
        $rotate = $transform['rotate'] ?? 0;
        $flip = $transform['flip'] ?? null;

        if ($crop !== null && ! is_array($crop)) {
            throw new InvalidImageSpec('A crop is an array of x, y, width and height.');
        }
        if (! is_int($rotate)) {
            throw new InvalidImageSpec('A rotation is an integer number of degrees.');
        }
        if ($flip !== null && ! is_string($flip)) {
            throw new InvalidImageSpec('A flip is horizontal, vertical or both.');
        }

        return new self(
            crop: $crop === null ? null : Crop::fromArray($crop),
            rotate: $rotate,
            flip: $flip === null ? null : (Flip::tryFrom($flip) ?? throw new InvalidImageSpec("Invalid flip: {$flip}.")),
        );
    }

    public function isEmpty(): bool
    {
        return $this->crop === null && $this->rotate === 0 && $this->flip === null;
    }

    /**
     * The size of a source of this size after the transform.
     *
     * @throws InvalidImageSpec when the crop is outside the source
     */
    public function apply(Size $source): Size
    {
        $size = $source;

        if ($this->crop !== null) {
            if ($this->crop->x >= $source->width || $this->crop->y >= $source->height) {
                throw new InvalidImageSpec('The crop starts outside the source.');
            }

            $size = new Size(
                min($this->crop->width, $source->width - $this->crop->x),
                min($this->crop->height, $source->height - $this->crop->y),
            );
        }

        return $this->rotate % 180 === 0 ? $size : $size->swapped();
    }

    /**
     * @return array{crop?: array{x: int, y: int, width: int, height: int}, rotate?: int, flip?: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'crop' => $this->crop?->toArray(),
            'rotate' => $this->rotate === 0 ? null : $this->rotate,
            'flip' => $this->flip?->value,
        ], fn ($value) => $value !== null);
    }
}
