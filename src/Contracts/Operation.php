<?php

declare(strict_types=1);

namespace Folklore\Image\Contracts;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Intervention\Image\Interfaces\ImageInterface;

/**
 * An operation of a spec, such as `blur` or `grayscale` (docs/decisions/0002-rendering-engine.md).
 * Operations are registered by name, and their arguments are the package's own vocabulary,
 * so URLs and presets don't depend on the engine.
 *
 * Built-in and project operations use this contract. Implementations are tested with
 * Folklore\Image\Testing\Contracts\OperationContractTests.
 */
interface Operation
{
    /**
     * Checks the arguments of a spec, and returns them in their canonical form, so equal
     * operations have the same URL: `blur-05` is not `blur-5`. Arguments are lowercase letters
     * and digits; decimals are scaled integers (a gamma of 1.2 is `120`).
     *
     * @param  list<string>  $arguments
     * @return list<string>
     *
     * @throws InvalidImageSpec
     */
    public function arguments(array $arguments): array;

    /**
     * Applies the operation to an image, with canonical arguments.
     *
     * @param  list<string>  $arguments
     */
    public function apply(ImageInterface $image, array $arguments): ImageInterface;
}
