<?php

declare(strict_types=1);

namespace Folklore\Image\Contracts;

use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\StoredDerivative;

/**
 * Where derivatives are kept once rendered (docs/decisions/0003-delivery.md): DiskStore (any
 * Laravel disk, the default), PublicPathStore (the 1.x static cache) or NullStore (nothing).
 * Rendering takes a lock on the key, so a store doesn't need to handle concurrent puts of
 * the same key.
 *
 * Implementations are tested with Folklore\Image\Testing\Contracts\DerivativeStoreContractTests.
 */
interface DerivativeStore
{
    public function get(DerivativeKey $key): ?StoredDerivative;

    /**
     * Stores a rendered image, replacing what the key had, and returns it as stored.
     */
    public function put(DerivativeKey $key, RenderedImage $image): StoredDerivative;

    /**
     * Deletes every derivative of a source, all versions included.
     *
     * @return int the number of derivatives deleted
     */
    public function clear(ImageSource $source): int;

    /**
     * A URL to redirect to instead of streaming the derivative (`image.delivery.serve =
     * 'redirect'`), or null when the store can't give one, such as a private disk.
     */
    public function url(DerivativeKey $key): ?string;
}
