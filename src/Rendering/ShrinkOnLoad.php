<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering;

use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Size;

/**
 * How much smaller a source can be decoded for a spec: JPEG and WebP decoders divide the
 * dimensions by 2, 4 or 8 while they read the file, so a 10,000 px original rendered at
 * 3,200 px never needs its full size in memory.
 */
final class ShrinkOnLoad
{
    /** The factors the decoders support, largest first. */
    public const array FACTORS = [8, 4, 2];

    /**
     * The largest factor that keeps the part of the source the spec renders at least as large
     * as the output on both axes, so the final resize only ever reduces; 1 when none does.
     *
     * @param  Size  $upright  the size of the upright original
     */
    public static function factor(Size $upright, ImageSpec $spec, bool $upscale): int
    {
        $region = $spec->transform?->apply($upright) ?? $upright;
        $output = $spec->outputSize($upright, $upscale);

        foreach (self::FACTORS as $factor) {
            if ($output->width * $factor <= $region->width && $output->height * $factor <= $region->height) {
                return $factor;
            }
        }

        return 1;
    }
}
