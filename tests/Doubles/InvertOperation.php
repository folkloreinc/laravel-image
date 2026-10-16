<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Doubles;

use Folklore\Image\Contracts\Operation;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * Inverts the colors, `strength` times (an argument from 1 to 3), to test the contract.
 */
final class InvertOperation implements Operation
{
    public function arguments(array $arguments): array
    {
        if (count($arguments) > 1) {
            throw new InvalidImageSpec('invert takes one argument at most.');
        }
        if ($arguments === []) {
            return [];
        }
        if (preg_match('/^0*([1-3])$/', $arguments[0], $matches) !== 1) {
            throw new InvalidImageSpec('The strength of invert is from 1 to 3.');
        }

        return $matches[1] === '1' ? [] : [$matches[1]];
    }

    public function apply(ImageInterface $image, array $arguments): ImageInterface
    {
        for ($times = (int) ($arguments[0] ?? 1); $times > 0; $times--) {
            $image = $image->invert();
        }

        return $image;
    }
}
