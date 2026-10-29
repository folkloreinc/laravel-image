<?php

declare(strict_types=1);

namespace Folklore\Image;

use Folklore\Image\Compat\Deprecations;
use Folklore\Image\Compat\LegacyConfig;
use Folklore\Image\Compat\LegacyFilters;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Contracts\Signer;
use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Http\LegacyImageRoute;
use Folklore\Image\Sources\SourceResolvers;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Urls\ImageUrl;
use Folklore\Image\Urls\V1Codec;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;

/**
 * The entry point behind the Image facade.
 *
 *     Image::for($media)->preset('card')->url();
 *
 * Its services come from the container when they are used, so Image::fake() can replace them.
 */
class ImageManager
{
    private ?string $host = null;

    public function __construct(
        protected Container $container,
    ) {}

    /**
     * Starts describing an image of a reference: a path, a media, a `media://…` reference,
     * anything a resolver supports, or an ImageSource.
     *
     * @throws UnsupportedReference when no resolver supports the reference
     * @throws SourceNotFound when the reference points to nothing
     */
    public function for(mixed $reference): PendingImage
    {
        return new PendingImage($this, $this->resolvers()->resolve($reference));
    }

    /**
     * Registers the 1.x image routes, as 1.x did: the routes file of `image.routes.map` (or of
     * `$config`), in a group of the `domain`, `prefix`, `as`, `namespace` and `middleware` of
     * `image.routes`, or the default legacy route when there is no such file. The file registers
     * them with `$router->image('{pattern}', [...])`, which is the legacy route
     * (docs/decisions/0011-image-routes-is-the-1x-route.md). The 2.x route is Route::image().
     *
     * @deprecated Register the legacy route with Route::imageLegacy(), and the 2.x route with Route::image().
     *
     * @param  array<string, mixed>|string  $config  options over `image.routes`, or the path of a routes file
     */
    public function routes(array|string $config = []): void
    {
        Deprecations::report('Image::routes() is deprecated: register Route::imageLegacy() for 1.x URLs, and Route::image() for 2.x URLs.');

        $config = [...$this->container->make(LegacyConfig::class)->routes(), ...(is_string($config) ? ['map' => $config] : $config)];
        $group = array_intersect_key($config, array_flip(['domain', 'prefix', 'as', 'namespace', 'middleware']));
        $map = $config['map'] ?? null;
        $codec = $this->container->make(V1Codec::class);

        $this->container->make(Router::class)->group($group, function (Router $router) use ($map, $codec): void {
            if (is_string($map) && is_file($map)) {
                require $map;
            } else {
                LegacyImageRoute::register($router, $codec, '{pattern}', [...LegacyImageRoute::DEFAULTS, 'as' => 'image']);
            }
        });
    }

    /**
     * A 1.x URL, as 1.x made it: `/uploads/photo-filters(300x300-crop).jpg`, with the template
     * of the 1.x `image.url` config. Filters are named filters (`['large']`) or options, in the
     * order they are given; `host`, `pattern` (options of the template) and `route` (the name of
     * a legacy route whose options and domain to use) are options of the URL.
     *
     *     Image::url('uploads/photo.jpg', 300, 300);
     *     Image::url('uploads/photo.jpg', ['large', 'format' => 'webp']);
     *
     * @deprecated Use the builder: Image::for($path)->preset('large')->url().
     *
     * @param  int|array<int|string, mixed>|null  $width  the width, or the filters
     * @param  array<int|string, mixed>  $filters
     */
    public function url(?string $src, int|array|null $width = null, ?int $height = null, array $filters = []): ?string
    {
        Deprecations::report('Image::url() and image_url() are deprecated: use Image::for($path)->preset($name)->url().');

        if ($src === null || $src === '') {
            return null;
        }

        if (is_array($width)) {
            [$filters, $width, $height] = [$width, null, null];
        }
        if ($width !== null) {
            $filters['width'] = $width;
        }
        if ($height !== null) {
            $filters['height'] = $height;
        }

        $config = array_intersect_key($filters, array_flip(['route', 'pattern', 'host']));
        $filters = array_diff_key($filters, $config);
        $routeName = is_string($config['route'] ?? null) ? $config['route'] : null;
        $route = $routeName === null ? null : $this->container->make(Router::class)->getRoutes()->getByName($routeName);
        if ($route instanceof Route) {
            $options = $route->getAction('image');
            $options = is_array($options) ? $options : [];
            $domain = $route->getDomain();
            $config = [...$options, ...($domain === null ? [] : ['host' => $domain]), ...$config];
        }

        $pattern = $config['pattern'] ?? [];
        $host = $config['host'] ?? null;
        $url = $this->container->make(V1Codec::class)
            ->withOptions(is_array($pattern) ? $pattern : [])
            ->make($src, $filters, is_string($host) ? $host : null, relative: $route instanceof Route);

        if ($route instanceof Route) {
            return str_replace('__URL__', ltrim($url, '/'), $this->container->make(UrlGenerator::class)->route($routeName, ['__URL__']));
        }

        return $url;
    }

    /**
     * Registers a 1.x filter. An array of options is a named filter, that 1.x URLs expand, and a
     * preset of the same name. Filters written against Imagine, closures and classes, can't be
     * applied by 2.x: rewrite them as operations (docs/upgrade.md). They are ignored, and URLs
     * that use them answer 404.
     *
     *     Image::filter('thumbnail', ['width' => 300, 'height' => 300, 'crop' => true]);
     *
     * @deprecated Configure presets in image.presets, or register them with Image::presets()->register().
     */
    public function filter(string $name, mixed $filter): static
    {
        if (! is_array($filter)) {
            Deprecations::report("The 1.x filter {$name} is written against Imagine, which 2.x doesn't use: rewrite it as an operation (docs/upgrade.md). URLs that use it answer 404.");

            return $this;
        }

        Deprecations::report('Image::filter() is deprecated: configure presets in image.presets.');
        $this->container->make(LegacyFilters::class)->register($name, $filter);

        return $this;
    }

    /**
     * Registers a resolver, such as the `media` resolver of laravel-mediatheque 2.
     */
    public function resolver(SourceResolver $resolver): void
    {
        $this->resolvers()->register($resolver);
    }

    /**
     * The host of the URLs built during this request, instead of `image.url.host`: for a site
     * with several domains. Null goes back to the config's.
     */
    public function useHost(?string $host): void
    {
        $this->host = $host === null ? null : rtrim($host, '/');
    }

    /**
     * The full URL of an image URL: the host, the prefix and the encoded path.
     */
    public function fullUrl(ImageUrl $url, ?string $host = null): string
    {
        $config = $this->config();
        $path = ltrim($config->prefix().'/'.$this->codec()->encode($url), '/');
        $host ??= $this->host ?? $config->host();

        return $host === null ? $this->container->make(UrlGenerator::class)->to($path) : "{$host}/{$path}";
    }

    public function presets(): PresetRegistry
    {
        return $this->container->make(PresetRegistry::class);
    }

    public function resolvers(): SourceResolvers
    {
        return $this->container->make(SourceResolvers::class);
    }

    public function codec(): UrlCodec
    {
        return $this->container->make(UrlCodec::class);
    }

    public function signer(): Signer
    {
        return $this->container->make(Signer::class);
    }

    public function renderer(): Renderer
    {
        return $this->container->make(Renderer::class);
    }

    /**
     * @internal
     */
    public function config(): ImageConfig
    {
        return $this->container->make(ImageConfig::class);
    }

    /**
     * The current Unix time, which tests can travel through.
     *
     * @internal
     */
    public function now(): int
    {
        return Carbon::now()->getTimestamp();
    }
}
