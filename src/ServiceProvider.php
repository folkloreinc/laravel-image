<?php

declare(strict_types=1);

namespace Folklore\Image;

use Illuminate\Support\ServiceProvider as BaseServiceProvider;

/**
 * The 1.x service provider, which 1.x projects list in config/app.php. It registers
 * ImageServiceProvider, which package discovery registers too, once.
 *
 * @deprecated Remove it from config/app.php: package discovery registers ImageServiceProvider.
 */
final class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->register(ImageServiceProvider::class);
    }
}
