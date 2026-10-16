<?php

declare(strict_types=1);

namespace Folklore\Image;

use Illuminate\Contracts\Container\Container;

/**
 * The entry point behind the Image facade. The builder (`Image::for()`) lands in alpha 1
 * (docs/roadmap.md); until then, it only gives the fake its place.
 */
class ImageManager
{
    public function __construct(
        protected Container $container,
    ) {}
}
