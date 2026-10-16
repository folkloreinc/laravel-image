<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;

/**
 * The largest rendering an image URL can ask for (`image.limits`). They are enforced on every
 * request, signed or not (docs/decisions/0005-secure-by-default.md). A null limit is no limit.
 */
final readonly class Limits
{
    public function __construct(
        public ?int $maxWidth = null,
        public ?int $maxHeight = null,
        public ?int $maxPixels = null,
        public ?int $maxOperations = null,
    ) {}

    /**
     * Checks a spec, and the size of its output when the source's size is known.
     *
     * @throws InvalidImageSpec with the limit that is exceeded
     */
    public function check(ImageSpec $spec, ?Size $output = null): void
    {
        if ($this->maxOperations !== null && count($spec->operations) > $this->maxOperations) {
            throw new InvalidImageSpec("More than {$this->maxOperations} operations.");
        }

        $sizes = array_filter([
            $output,
            $spec->width !== null && $spec->height !== null ? new Size($spec->width, $spec->height) : null,
        ]);

        foreach ([$spec->width, $output?->width] as $width) {
            if ($width !== null && $this->maxWidth !== null && $width > $this->maxWidth) {
                throw new InvalidImageSpec("A width of {$width} is over the limit of {$this->maxWidth}.");
            }
        }
        foreach ([$spec->height, $output?->height] as $height) {
            if ($height !== null && $this->maxHeight !== null && $height > $this->maxHeight) {
                throw new InvalidImageSpec("A height of {$height} is over the limit of {$this->maxHeight}.");
            }
        }
        foreach ($sizes as $size) {
            $pixels = $size->width * $size->height;
            if ($this->maxPixels !== null && $pixels > $this->maxPixels) {
                throw new InvalidImageSpec("{$pixels} pixels is over the limit of {$this->maxPixels}.");
            }
        }
    }
}
