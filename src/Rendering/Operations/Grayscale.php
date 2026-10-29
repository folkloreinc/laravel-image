<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering\Operations;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Turns the image to shades of grey, as the 1.x `grayscale` filter: `grayscale`, without arguments.
 */
final class Grayscale implements Operation
{
    public function arguments(array $arguments): array
    {
        return $arguments === [] ? [] : throw new InvalidImageSpec('The grayscale operation takes no arguments.');
    }

    public function apply(ImageInterface $image, array $arguments): ImageInterface
    {
        return $image->grayscale();
    }
}
