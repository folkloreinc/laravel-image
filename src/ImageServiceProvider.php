<?php

declare(strict_types=1);

namespace Folklore\Image;

use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Urls\V2Codec;
use Illuminate\Support\ServiceProvider;

final class ImageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImageManager::class);
        $this->app->singleton(UrlCodec::class, V2Codec::class);
    }
}
