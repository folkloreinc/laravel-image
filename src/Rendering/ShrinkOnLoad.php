<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering;

use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Size;

/**
 * How much smaller a source can be decoded for a spec: JPEG and WebP decoders divide the
 * dimensions by 2, 4 or 8 while they read the file, so a 10,000 px original rendered at
 * 3,200 px never needs its full size in memory.
 *
 * The decoder's shrink is cruder than the resize that follows it, so the factor leaves that
 * resize at least 2× to do, as libvips' own thumbnail does. Large originals, where memory
 * matters more than the last detail, only keep the resize from enlarging: rendered at the
 * size of the shrunk image, fine detail such as text is slightly softer than from a full
 * decode, and the image can move by half a pixel.
 */
final class ShrinkOnLoad
{
    /** The factors the decoders support, largest first. */
    public const array FACTORS = [8, 4, 2];

    /**
     * Above this many pixels, an original is large: decoded whole, it would take more than
     * about 100 MB (4 bytes per pixel), and twice that when it is turned upright.
     */
    public const int LARGE_ORIGINAL = 24_000_000;

    /**
     * The largest factor that keeps the part of the source the spec renders at least twice
     * as large as the output on both axes, or at least as large for a large original; 1 when
     * none does.
     *
     * @param  Size  $upright  the size of the upright original
     */
    public static function factor(Size $upright, ImageSpec $spec, bool $upscale): int
    {
        $region = $spec->transform?->apply($upright) ?? $upright;
        $output = $spec->outputSize($upright, $upscale);
        $margin = $upright->width * $upright->height > self::LARGE_ORIGINAL ? 1 : 2;

        foreach (self::FACTORS as $factor) {
            if ($output->width * $factor * $margin <= $region->width && $output->height * $factor * $margin <= $region->height) {
                return $factor;
            }
        }

        return 1;
    }
}
