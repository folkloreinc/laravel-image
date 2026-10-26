<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Contracts\Preset;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Exceptions\RenderInProgress;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\PresetSpecKey;
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
     * under the RenderLock of its key. When the spec is a preset's, rendering also records the
     * preset's spec at its version, so URLs of this version keep finding their derivatives once
     * the preset changes (docs/decisions/0012-earlier-preset-versions.md).
     *
     * @throws RenderFailed when the source can't be rendered
     * @throws RenderInProgress when another request is still rendering it
     */
    public function fetch(ImageSource $source, ImageSpec $spec, ?Preset $preset = null): StoredDerivative
    {
        $store = $this->store();

        return $this->container->make(RenderLock::class)->remember(
            $store,
            DerivativeKey::for($source, $spec),
            function () use ($store, $source, $spec, $preset) {
                $rendered = $this->container->make(Renderer::class)->render($source, $spec);
                if ($preset !== null) {
                    $store->putPresetSpec(
                        PresetSpecKey::for($source, PresetRegistry::reference($preset)),
                        $preset->spec($source),
                    );
                }

                return $rendered;
            },
        );
    }

    /**
     * The spec a preset had at an earlier version, recorded when a derivative of that source
     * version was rendered from it, or null.
     */
    public function presetSpec(PresetSpecKey $key): ?ImageSpec
    {
        return $this->store()->presetSpec($key);
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
