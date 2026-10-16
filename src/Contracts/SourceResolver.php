<?php

declare(strict_types=1);

namespace Folklore\Image\Contracts;

use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Sources\ImageSource;

/**
 * Turns a reference into an image source, and the key found in a URL back into the same
 * source (docs/decisions/0006-sources-and-resolvers.md). The package ships the `disk`
 * resolver; `laravel-mediatheque` 2 ships the `media` resolver.
 *
 * Implementations are tested with Folklore\Image\Testing\Contracts\SourceResolverContractTests.
 */
interface SourceResolver
{
    /**
     * The name of the resolver in URLs and in the config: `disk`, `media`.
     */
    public function name(): string;

    /**
     * Whether the resolver can resolve this reference: a path, a `media://…` reference, a model…
     */
    public function supports(mixed $reference): bool;

    /**
     * Resolves a reference from code. Missing metadata is read once and cached, so the
     * source has its dimensions when it can.
     *
     * @throws UnsupportedReference when the resolver doesn't support the reference
     * @throws SourceNotFound when the reference points to nothing
     */
    public function resolve(mixed $reference): ImageSource;

    /**
     * Resolves the key of a URL (ImageSource::$key) back into the same source. The key comes
     * from a request: implementations check it (path containment for disks).
     *
     * @throws SourceNotFound when the key points to nothing, or is not allowed
     */
    public function resolveKey(string $key): ImageSource;
}
