<?php

declare(strict_types=1);

namespace Folklore\Image\Storage;

use Closure;
use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Exceptions\RenderInProgress;
use Folklore\Image\Rendering\RenderedImage;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * One render per derivative (docs/decisions/0003-delivery.md): concurrent misses of the same
 * derivative wait for an atomic lock on its key (`Cache::lock()`), and those that waited read
 * what the first one stored. It works with any DerivativeStore.
 *
 * The lock store must be shared by every application server (Redis, the database…).
 */
final readonly class RenderLock
{
    /**
     * @param  int  $seconds  how long a lock is held at most, if its render never finishes
     * @param  int  $wait  how long a request waits for another one's render, in seconds
     */
    public function __construct(
        private LockProvider $locks,
        private int $seconds = 60,
        private int $wait = 30,
    ) {}

    /**
     * The stored derivative of the key, or the derivative rendered and stored once.
     *
     * @param  Closure(): RenderedImage  $render
     *
     * @throws RenderInProgress when another request's render of the key didn't finish in time
     */
    public function remember(DerivativeStore $store, DerivativeKey $key, Closure $render): StoredDerivative
    {
        $stored = $store->get($key);
        if ($stored !== null) {
            return $stored;
        }

        $lock = $this->locks->lock('folklore-image:render:'.hash('sha256', $key->path()), $this->seconds);

        try {
            $lock->block($this->wait);
        } catch (LockTimeoutException $exception) {
            throw new RenderInProgress("Timed out waiting for another render of {$key->path()}.", previous: $exception);
        }

        try {
            return $store->get($key) ?? $store->put($key, $render());
        } finally {
            $lock->release();
        }
    }
}
