<?php

declare(strict_types=1);

namespace Folklore\Image\Http;

use Folklore\Image\ImageConfig;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The image route of 2.x, which `Route::image()` registers: `GET /{prefix}/{path}`, named
 * `image`. Nothing is served until a project calls it (docs/decisions/0005-secure-by-default.md),
 * with its own middleware: `Route::image()->middleware(...)`. `Image::routes()` is the 1.x
 * route, not this one (docs/decisions/0011-image-routes-is-the-1x-route.md).
 *
 * @internal
 */
final class ImageRoute
{
    /**
     * @param  string|null  $prefix  the path before image URLs; `image.url.prefix` by default, which
     *                               is the prefix of the URLs the builder makes
     */
    public static function register(Router $router, ImageConfig $config, ?string $prefix = null): Route
    {
        $prefix = trim($prefix ?? $config->prefix(), '/');

        return $router
            ->get(($prefix === '' ? '' : "{$prefix}/").'{path}', ImageController::class)
            ->where('path', '.+')
            ->name('image');
    }
}
