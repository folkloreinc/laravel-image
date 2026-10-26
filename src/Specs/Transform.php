<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;

/**
 * Non-destructive edits, applied to the upright original before anything else, in this
 * order: crop (in pixels of the upright original), rotate (clockwise), flip. It is Panneau
 * 5's `MediaTransform`, stored on a media or on a field value, focal point included
 * (docs/decisions/0013-transform-focal-point-and-metadata.md).
 *
 * The focal point is relative to the upright original, not to the crop, so changing the crop
 * doesn't move it; focus() maps it into the transformed image. ImageSpec and ImageSource keep
 * it apart from the other edits, as their focus and focalPoint.
 */
final readonly class Transform
{
    /** Clockwise, in degrees: 0, 90, 180 or 270. */
    public int $rotate;

    public function __construct(
        public ?Crop $crop = null,
        int $rotate = 0,
        public ?Flip $flip = null,
        public ?Focus $focalPoint = null,
    ) {
        $normalized = (($rotate % 360) + 360) % 360;
        if ($normalized % 90 !== 0) {
            throw new InvalidImageSpec("Rotations are multiples of 90 degrees, not {$rotate}.");
        }

        $this->rotate = $normalized;
    }

    /**
     * Panneau 5's shape (`MediaTransform`), where unknown keys are ignored:
     *
     *     ['crop' => ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 600], 'rotate' => 90,
     *      'flipX' => true, 'flipY' => false, 'focalPoint' => ['x' => 0.3, 'y' => 0.6]]
     *
     * `flipX` mirrors left to right (horizontal), `flipY` top to bottom (vertical). The spec
     * form, `'flip' => 'horizontal' | 'vertical' | 'both'`, is read too, but not with them.
     *
     * @param  array<mixed>  $transform
     */
    public static function fromArray(array $transform): self
    {
        $crop = $transform['crop'] ?? null;
        $rotate = $transform['rotate'] ?? 0;
        $focalPoint = $transform['focalPoint'] ?? null;

        if ($crop !== null && ! is_array($crop)) {
            throw new InvalidImageSpec('A crop is an array of x, y, width and height.');
        }
        if (! is_int($rotate)) {
            throw new InvalidImageSpec('A rotation is an integer number of degrees.');
        }
        if ($focalPoint !== null && ! is_array($focalPoint)) {
            throw new InvalidImageSpec('A focal point is an array of x and y.');
        }

        return new self(
            crop: $crop === null ? null : Crop::fromArray($crop),
            rotate: $rotate,
            flip: self::flipFromArray($transform),
            focalPoint: $focalPoint === null ? null : Focus::fromArray($focalPoint),
        );
    }

    /**
     * The same edits without the focal point.
     */
    public function withoutFocalPoint(): self
    {
        return new self($this->crop, $this->rotate, $this->flip);
    }

    /**
     * Where a point of the upright original of this size is in the transformed image: the crop
     * moves it (a point outside the crop goes to its nearest edge), then the rotation and the
     * flip turn it with the image.
     */
    public function focus(Focus $focus, Size $source): Focus
    {
        [$x, $y] = [$focus->x, $focus->y];

        if ($this->crop !== null) {
            $width = max(1, min($this->crop->width, $source->width - $this->crop->x));
            $height = max(1, min($this->crop->height, $source->height - $this->crop->y));
            $x = max(0, min(1, ($x * $source->width - $this->crop->x) / $width));
            $y = max(0, min(1, ($y * $source->height - $this->crop->y) / $height));
        }

        // Clockwise: the top left corner goes to the top right at 90°.
        [$x, $y] = match ($this->rotate) {
            90 => [1 - $y, $x],
            180 => [1 - $x, 1 - $y],
            270 => [$y, 1 - $x],
            default => [$x, $y],
        };

        if ($this->flip === Flip::Horizontal || $this->flip === Flip::Both) {
            $x = 1 - $x;
        }
        if ($this->flip === Flip::Vertical || $this->flip === Flip::Both) {
            $y = 1 - $y;
        }

        return new Focus($x, $y);
    }

    public function isEmpty(): bool
    {
        return $this->crop === null && $this->rotate === 0 && $this->flip === null && $this->focalPoint === null;
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
     * The edits in the form of ImageSpec::fromArray(), with the focal point when there is one.
     *
     * @return array{crop?: array{x: int, y: int, width: int, height: int}, rotate?: int, flip?: string, focalPoint?: array{x: float, y: float}}
     */
    public function toArray(): array
    {
        return array_filter([
            'crop' => $this->crop?->toArray(),
            'rotate' => $this->rotate === 0 ? null : $this->rotate,
            'flip' => $this->flip?->value,
            'focalPoint' => $this->focalPoint?->toArray(),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<mixed>  $transform
     */
    private static function flipFromArray(array $transform): ?Flip
    {
        $flip = $transform['flip'] ?? null;
        $flipX = $transform['flipX'] ?? false;
        $flipY = $transform['flipY'] ?? false;

        if (! is_bool($flipX) || ! is_bool($flipY)) {
            throw new InvalidImageSpec('flipX and flipY are booleans.');
        }
        if ($flip !== null) {
            if ($flipX || $flipY) {
                throw new InvalidImageSpec('Give a flip, or flipX and flipY, not both.');
            }

            return is_string($flip) ? (Flip::tryFrom($flip) ?? throw new InvalidImageSpec("Invalid flip: {$flip}.")) : throw new InvalidImageSpec('A flip is horizontal, vertical or both.');
        }

        return match (true) {
            $flipX && $flipY => Flip::Both,
            $flipX => Flip::Horizontal,
            $flipY => Flip::Vertical,
            default => null,
        };
    }
}
