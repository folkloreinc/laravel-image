<?php

declare(strict_types=1);

namespace Folklore\Image;

use Folklore\Image\Contracts\Signer;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Sources\SourceResolvers;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Urls\HmacSigner;
use Folklore\Image\Urls\V2Codec;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;

final class ImageServiceProvider extends ServiceProvider
{
    /**
     * Keys whose value replaces the package's default instead of being merged with it.
     */
    private const array REPLACED_KEYS = ['presets'];

    public function register(): void
    {
        $this->mergeImageConfig();

        $this->app->singleton(ImageConfig::class, fn (Container $app) => new ImageConfig($app->make(Repository::class)));
        $this->app->singleton(ImageManager::class);
        $this->app->singleton(UrlCodec::class, V2Codec::class);
        $this->app->singleton(Signer::class, fn (Container $app) => new HmacSigner($app->make(ImageConfig::class)->signerKeys()));
        $this->app->singleton(PresetRegistry::class, fn (Container $app) => new PresetRegistry($app, $app->make(ImageConfig::class)->presets()));
        $this->app->singleton(SourceResolvers::class, fn (Container $app) => new SourceResolvers($app, $app->make(ImageConfig::class)->resolvers()));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/image.php' => $this->app->configPath('image.php')], 'image-config');
        }
    }

    /**
     * Fills in the keys a published config doesn't set with the package's defaults, nested
     * keys included, so a published config only needs the keys it changes. Lists and presets
     * replace the defaults.
     */
    private function mergeImageConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        $config = $this->app->make(Repository::class);
        $defaults = require __DIR__.'/../config/image.php';
        $published = $config->get('image', []);

        $config->set('image', self::merge(is_array($defaults) ? $defaults : [], is_array($published) ? $published : []));
    }

    /**
     * @param  array<mixed>  $defaults
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function merge(array $defaults, array $values, bool $root = true): array
    {
        foreach ($values as $key => $value) {
            $default = $defaults[$key] ?? null;
            $replaced = $root && in_array($key, self::REPLACED_KEYS, true);

            $defaults[$key] = ! $replaced && is_array($value) && is_array($default) && ! array_is_list($value) && ! array_is_list($default)
                ? self::merge($default, $value, false)
                : $value;
        }

        return $defaults;
    }
}
