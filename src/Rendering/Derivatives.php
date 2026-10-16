<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Exceptions\RenderInProgress;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\RenderLock;
use Folklore\Image\Storage\StoredDerivative;
use Illuminate\Contracts\Container\Container;

/**
 * The derivatives the controller serves, from the configured store: a stored derivative is
 * served as it is; otherwise it is rendered once, under the RenderLock of its key
 * (docs/decisions/0003-delivery.md). The store, the renderer and the lock come from the
 * container when used, so Image::fake() can replace them.
 */
final readonly class Derivatives
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * The stored derivative of a source and a spec, rendered and stored first when needed,
     * under the RenderLock of its key.
     *
     * @throws RenderFailed when the source can't be rendered
     * @throws RenderInProgress when another request is still rendering it
     */
    public function fetch(ImageSource $source, ImageSpec $spec): StoredDerivative
    {
        return $this->container->make(RenderLock::class)->remember(
            $this->store(),
            DerivativeKey::for($source, $spec),
            fn () => $this->container->make(Renderer::class)->render($source, $spec),
        );
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

    private function store(): DerivativeStore
    {
        return $this->container->make(DerivativeStore::class);
    }
}
