<?php

declare(strict_types=1);

namespace App\Providers;

use Folklore\Image\Contracts\UrlGenerator;
use Illuminate\Support\ServiceProvider;

// The service provider of a 1.x project: its named sizes become filters at boot.
class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach (config('image.sizes') as $size) {
            $this->app['image']->filter($size['id'], $size);
        }

        $this->app['image']->filter('circle', function ($image) {
            return $image;
        });

        $this->app->make(UrlGenerator::class);
    }
}
