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

        $crop = $this->cropIn($source);
        if ($crop !== null) {
            $x = max(0, min(1, ($x * $source->width - $crop->x) / $crop->width));
            $y = max(0, min(1, ($y * $source->height - $crop->y) / $crop->height));
        }

        [$quarterTurns, $mirrored] = $this->turn();
        [$x, $y] = self::turnPoint([$x, $y], $quarterTurns, $mirrored);

        return new Focus($x, $y);
    }

    /**
     * The crop as it applies to a source of this size: clamped to the source when it goes past
     * its edges, or null when there is none or it is wholly outside, which leaves the whole
     * source rather than failing (an edit made on another version of the image).
     */
    public function cropIn(Size $source): ?Crop
    {
        if ($this->crop === null || $this->crop->x >= $source->width || $this->crop->y >= $source->height) {
            return null;
        }

        return new Crop(
            $this->crop->x,
            $this->crop->y,
            min($this->crop->width, $source->width - $this->crop->x),
            min($this->crop->height, $source->height - $this->crop->y),
        );
    }

    /**
     * This transform, made over the image another one gives (Panneau 5's `base: "media"`), as
     * one transform of the upright original of this size: its crop and focal point, relative to
     * that image, are mapped back to the original, and the quarter turns and flips of both add
     * up (docs/decisions/0014-per-usage-transforms.md).
     *
     * A crop past the edges of the base's image is clamped to it, and one wholly outside leaves
     * the whole image. The focal point of the result is this transform's, mapped back, or none.
     *
     * @param  Size|null  $source  the size of the upright original; needed when the base turns,
     *                             flips or crops the image and this transform has a crop or a
     *                             focal point to map back
     *
     * @throws InvalidImageSpec when the size of the original is needed but unknown
     */
    public function over(self $base, ?Size $source): self
    {
        [$baseTurns, $baseMirrored] = $base->turn();
        if ($base->crop === null && $baseTurns === 0 && ! $baseMirrored) {
            return $this;
        }

        [$turns, $mirrored] = self::compose($base->turn(), $this->turn());
        [$rotate, $flip] = self::fromTurn($turns, $mirrored);
        if ($this->crop === null && $this->focalPoint === null) {
            return new self($base->crop, $rotate, $flip);
        }
        if ($source === null) {
            throw new InvalidImageSpec('A transform over the media\'s own needs the dimensions of the original.');
        }

        // The part of the original the base keeps, and the size of the image it gives.
        $region = $base->cropIn($source) ?? new Crop(0, 0, $source->width, $source->height);
        $image = $baseTurns % 2 === 0 ? new Size($region->width, $region->height) : new Size($region->height, $region->width);

        $crop = $this->cropIn($image);
        if ($crop !== null) {
            $crop = self::unturnCrop($crop, $image, $baseTurns, $baseMirrored);
            $crop = new Crop($region->x + $crop->x, $region->y + $crop->y, $crop->width, $crop->height);
        } elseif ($base->crop !== null) {
            $crop = $region;
        }

        $focalPoint = null;
        if ($this->focalPoint !== null) {
            [$x, $y] = self::unturnPoint([$this->focalPoint->x, $this->focalPoint->y], $baseTurns, $baseMirrored);
            $focalPoint = new Focus(
                max(0, min(1, ($region->x + $x * $region->width) / $source->width)),
                max(0, min(1, ($region->y + $y * $region->height) / $source->height)),
            );
        }

        return new self($crop, $rotate, $flip, $focalPoint);
    }

    public function isEmpty(): bool
    {
        return $this->crop === null && $this->rotate === 0 && $this->flip === null && $this->focalPoint === null;
    }

    /**
     * The size of a source of this size after the transform. A crop is clamped to the source,
     * and one wholly outside leaves the whole source (cropIn()).
     */
    public function apply(Size $source): Size
    {
        $crop = $this->cropIn($source);
        $size = $crop === null ? $source : new Size($crop->width, $crop->height);

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

    /**
     * The rotation and the flip as quarter turns clockwise, then an optional mirror left to
     * right: a flip top to bottom is a half turn and a mirror, both flips a half turn.
     *
     * @return array{int, bool}
     */
    private function turn(): array
    {
        $turns = intdiv($this->rotate, 90);

        return match ($this->flip) {
            null => [$turns, false],
            Flip::Horizontal => [$turns, true],
            Flip::Vertical => [($turns + 2) % 4, true],
            Flip::Both => [($turns + 2) % 4, false],
        };
    }

    /**
     * The rotation and the flip of quarter turns and a mirror, with a flip top to bottom
     * rather than three quarter turns and a mirror.
     *
     * @return array{int, ?Flip}
     */
    private static function fromTurn(int $turns, bool $mirrored): array
    {
        return match (true) {
            ! $mirrored => [$turns * 90, null],
            $turns < 2 => [$turns * 90, Flip::Horizontal],
            default => [($turns - 2) * 90, Flip::Vertical],
        };
    }

    /**
     * The quarter turns and mirror of applying one, then the other.
     *
     * @param  array{int, bool}  $first
     * @param  array{int, bool}  $then
     * @return array{int, bool}
     */
    private static function compose(array $first, array $then): array
    {
        // A point off every axis of symmetry tells the eight apart.
        $point = self::turnPoint(self::turnPoint([0.1, 0.3], ...$first), ...$then);
        foreach ([false, true] as $mirrored) {
            foreach (range(0, 3) as $turns) {
                if (self::turnPoint([0.1, 0.3], $turns, $mirrored) === $point) {
                    return [$turns, $mirrored];
                }
            }
        }

        throw new \LogicException('Quarter turns and mirrors always compose.');
    }

    /**
     * Where a point, from 0 to 1 on each axis, goes with quarter turns clockwise, then a mirror.
     *
     * @param  array{float, float}  $point
     * @return array{float, float}
     */
    private static function turnPoint(array $point, int $turns, bool $mirrored): array
    {
        [$x, $y] = $point;
        for ($turn = 0; $turn < $turns; $turn++) {
            // Clockwise: the top left corner goes to the top right.
            [$x, $y] = [round(1 - $y, 6), $x];
        }

        return $mirrored ? [round(1 - $x, 6), $y] : [$x, $y];
    }

    /**
     * Where a point of the turned image was before the turns and the mirror.
     *
     * @param  array{float, float}  $point
     * @return array{float, float}
     */
    private static function unturnPoint(array $point, int $turns, bool $mirrored): array
    {
        [$x, $y] = $point;
        if ($mirrored) {
            $x = 1 - $x;
        }
        for ($turn = 0; $turn < $turns; $turn++) {
            [$x, $y] = [$y, 1 - $x];
        }

        return [$x, $y];
    }

    /**
     * The rectangle a crop of the turned image covers in the image before the turns and the
     * mirror, in whole pixels.
     */
    private static function unturnCrop(Crop $crop, Size $image, int $turns, bool $mirrored): Crop
    {
        [$x, $y, $width, $height] = [$crop->x, $crop->y, $crop->width, $crop->height];
        [$imageWidth, $imageHeight] = [$image->width, $image->height];

        if ($mirrored) {
            $x = $imageWidth - $x - $width;
        }
        for ($turn = 0; $turn < $turns; $turn++) {
            // A quarter turn clockwise takes (x, y) of a w×h image to (h - y, x) of an h×w one.
            [$x, $y, $width, $height] = [$y, $imageWidth - $x - $width, $height, $width];
            [$imageWidth, $imageHeight] = [$imageHeight, $imageWidth];
        }

        return new Crop($x, $y, $width, $height);
    }
}
