<?php

declare(strict_types=1);

namespace Folklore\Image;

use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Rendering\InterventionRenderer;
use Folklore\Image\Urls\V2Codec;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Support\ServiceProvider;

final class ImageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/image.php', 'image');

        $this->app->singleton(ImageManager::class);
        $this->app->singleton(UrlCodec::class, V2Codec::class);
        $this->app->singleton(Renderer::class, fn (Container $app) => self::renderer($app));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/image.php' => $this->app->configPath('image.php')], 'image-config');
        }
    }

    private static function renderer(Container $app): InterventionRenderer
    {
        $config = $app->make(Config::class);
        $memoryLimit = $config->get('image.memory_limit');
        $quality = [];
        foreach ($config->array('image.quality', []) as $format => $value) {
            if (is_string($format) && is_int($value)) {
                $quality[$format] = $value;
            }
        }

        return new InterventionRenderer(
            $app->make(FilesystemFactory::class),
            $config->string('image.renderer.driver', 'auto'),
            quality: $quality,
            upscale: $config->boolean('image.upscale', false),
            memoryLimit: is_string($memoryLimit) && $memoryLimit !== '' ? $memoryLimit : null,
        );
    }
}
