<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering\Operations;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Inverts the colors, as the 1.x `negative` filter: `negative`, without arguments.
 */
final class Negative implements Operation
{
    public function arguments(array $arguments): array
    {
        return $arguments === [] ? [] : throw new InvalidImageSpec('The negative operation takes no arguments.');
    }

    public function apply(ImageInterface $image, array $arguments): ImageInterface
    {
        return $image->invert();
    }
}
