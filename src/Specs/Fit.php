<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

/**
 * How an image fits a box of two dimensions (a width and a height, or one of them and a ratio).
 */
enum Fit: string
{
    /** Fills the box and crops what overflows, around the focal point. The default. */
    case Cover = 'cover';

    /** Fits inside the box, and pads the rest with the background. */
    case Contain = 'contain';

    /** Fits inside the box: the output is as large as possible without cropping or padding. */
    case Inside = 'inside';

    /** Fills the box exactly, distorting the image. */
    case Fill = 'fill';
}
