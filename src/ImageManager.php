<?php

declare(strict_types=1);

namespace Folklore\Image;

use BadMethodCallException;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Contracts\Signer;
use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Sources\SourceResolvers;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Urls\ImageUrl;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Routing\UrlGenerator;
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
     * The 1.x image route, which comes back with the compatibility layer of the beta, deprecated
     * (docs/decisions/0011-image-routes-is-the-1x-route.md). Until then it throws, so a 1.x
     * project that updates doesn't get the 2.x route instead. The 2.x route is Route::image().
     *
     * @param  array<string, mixed>|string  $config  the 1.x route options, or the path of a routes file
     *
     * @throws BadMethodCallException always, until the compatibility layer
     */
    public function routes(array|string $config = []): never
    {
        throw new BadMethodCallException('Image::routes() registers the 1.x image route, which comes back with the compatibility layer in 2.0 beta. Register the 2.x image route with Route::image().');
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
    public function url(ImageUrl $url, ?string $host = null): string
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
