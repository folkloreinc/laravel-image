<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\ImageConfig;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\StoredDerivative;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Container\Container;

/**
 * Renders each derivative once (docs/decisions/0003-delivery.md): a stored derivative is
 * served as it is; otherwise rendering takes a lock on the derivative key, so concurrent
 * misses of the same variant render once, and the requests that waited read what the first
 * one stored.
 *
 * The lock store is `image.derivatives.lock.store` (the default cache store when null). It
 * must be shared by every application server, such as Redis or the database.
 */
final readonly class Derivatives
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * The stored derivative of a source and a spec, rendered and stored first when needed.
     *
     * @throws LockTimeoutException when another request is still rendering it
     */
    public function fetch(ImageSource $source, ImageSpec $spec): StoredDerivative
    {
        $key = DerivativeKey::for($source, $spec);
        $store = $this->store();

        $stored = $store->get($key);
        if ($stored !== null) {
            return $stored;
        }

        $config = $this->container->make(ImageConfig::class);
        [$seconds, $wait] = $config->lockTimes();
        $cache = $this->container->make(CacheFactory::class)->store($config->lockStore())->getStore();
        if (! $cache instanceof LockProvider) {
            throw new InvalidImageConfig('The cache store of image.derivatives.lock.store has no locks: use redis, database, memcached, dynamodb, file or array.');
        }

        $stored = $cache->lock(self::lockName($key), $seconds)->block($wait, function () use ($store, $key, $source, $spec): StoredDerivative {
            return $store->get($key) ?? $store->put($key, $this->container->make(Renderer::class)->render($source, $spec));
        });

        return $stored instanceof StoredDerivative ? $stored : throw new RenderFailed("The derivative {$key->path()} wasn't stored.");
    }

    /**
     * A derivative already stored, without rendering it.
     */
    public function find(DerivativeKey $key): ?StoredDerivative
    {
        return $this->store()->get($key);
    }

    /**
     * The URL of a stored derivative on its store, for redirects, or null.
     */
    public function url(DerivativeKey $key): ?string
    {
        return $this->store()->url($key);
    }

    /**
     * The name of the lock of a derivative key.
     */
    public static function lockName(DerivativeKey $key): string
    {
        return 'folklore-image:render:'.hash('sha256', $key->path());
    }

    private function store(): DerivativeStore
    {
        return $this->container->make(DerivativeStore::class);
    }
}
