<?php

declare(strict_types=1);

use Folklore\Image\Compat\Deprecations;
use Folklore\Image\ImageManager;

if (! function_exists('image')) {
    /**
     * The image manager, as 1.x's `image()` helper: `image()->url($path, ['large'])`.
     *
     * @deprecated Use the Image facade: Image::for($path)->preset('large')->url().
     *
     * @param  array<string, mixed>  $options
     */
    function image(?string $path = null, array $options = []): ImageManager
    {
        if ($path !== null) {
            throw new BadMethodCallException('image($path, $options) made an Imagine image in 1.x, which 2.x no longer does. Render an image with Image::for($path)->render().');
        }

        Deprecations::report('image() is deprecated: use the Image facade.');

        return app(ImageManager::class);
    }
}

if (! function_exists('image_url')) {
    /**
     * A 1.x image URL, as 1.x's `image_url()` helper.
     *
     * @deprecated Use the builder: Image::for($path)->preset('large')->url().
     *
     * @param  int|array<int|string, mixed>|null  $width  the width, or the filters
     * @param  array<int|string, mixed>  $filters
     */
    function image_url(?string $src, int|array|null $width = null, ?int $height = null, array $filters = []): ?string
    {
        return app(ImageManager::class)->url($src, $width, $height, $filters);
    }
}
