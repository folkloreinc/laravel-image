<?php

declare(strict_types=1);

namespace Folklore\Image;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Contracts\Signer;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Rendering\InterventionRenderer;
use Folklore\Image\Sources\DiskResolver;
use Folklore\Image\Sources\SourceResolvers;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Storage\DiskStore;
use Folklore\Image\Storage\RenderLock;
use Folklore\Image\Urls\HmacSigner;
use Folklore\Image\Urls\V2Codec;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

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
        $this->app->singleton(SourceResolvers::class, function (Container $app): SourceResolvers {
            $resolvers = new SourceResolvers($app, $app->make(ImageConfig::class)->resolvers());
            $resolvers->extend('disk', fn (Container $app) => $app->make(DiskResolver::class));

            return $resolvers;
        });
        $this->app->singleton(Renderer::class, fn (Container $app) => self::renderer($app));
        $this->app->singleton(DiskResolver::class, fn (Container $app) => self::diskResolver($app));
        $this->app->singleton(DerivativeStore::class, fn (Container $app) => self::derivativeStore($app));
        $this->app->singleton(RenderLock::class, fn (Container $app) => self::renderLock($app));
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

    /**
     * The `disk` resolver, from `image.resolvers.disk` (`disk`, `root`, `version_ttl`).
     */
    private static function diskResolver(Container $app): DiskResolver
    {
        $config = $app->make(Config::class);

        return new DiskResolver(
            $app->make(FilesystemFactory::class),
            $app->make(Cache::class),
            'disk',
            $config->string('image.resolvers.disk.disk', 'public'),
            $config->string('image.resolvers.disk.root', ''),
            $config->integer('image.resolvers.disk.version_ttl', 300),
        );
    }

    /**
     * The store of `image.derivatives.store`: `disk`, or a class implementing DerivativeStore.
     */
    private static function derivativeStore(Container $app): DerivativeStore
    {
        $config = $app->make(Config::class);
        $store = $config->string('image.derivatives.store', 'disk');

        if ($store !== 'disk') {
            $instance = $app->make($store);

            return $instance instanceof DerivativeStore
                ? $instance
                : throw new InvalidArgumentException("The derivative store {$store} doesn't implement ".DerivativeStore::class.'.');
        }

        $disk = $config->string('image.derivatives.disk', 'public');

        return new DiskStore(
            $app->make(FilesystemFactory::class)->disk($disk),
            $config->string('image.derivatives.path', 'derivatives'),
            public: $config->get("filesystems.disks.{$disk}.visibility") === 'public',
        );
    }

    private static function renderLock(Container $app): RenderLock
    {
        $config = $app->make(Config::class);
        $name = $config->get('image.derivatives.lock.store');
        $store = $app->make(CacheFactory::class)->store(is_string($name) ? $name : null)->getStore();

        if (! $store instanceof LockProvider) {
            throw new InvalidArgumentException('The cache store of image.derivatives.lock.store must support locks.');
        }

        return new RenderLock(
            $store,
            $config->integer('image.derivatives.lock.seconds', 60),
            $config->integer('image.derivatives.lock.wait', 30),
        );
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
