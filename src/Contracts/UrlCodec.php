<?php

declare(strict_types=1);

namespace Folklore\Image\Contracts;

use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Urls\ImageUrl;

/**
 * Encodes image URLs into paths and decodes paths back (docs/decisions/0004-specs-and-urls.md).
 * V2Codec is the 2.x syntax; V1Codec (in the beta) decodes the 1.x `-filters(...)` URLs.
 *
 * Paths are relative to the route prefix, and percent-encoded: the decoder reads the raw path
 * of the request.
 */
interface UrlCodec
{
    /**
     * The path of a URL, after the route prefix.
     *
     * @throws InvalidImageUrl when the URL can't be encoded, such as an expiry without a signature
     */
    public function encode(ImageUrl $url): string;

    /**
     * The part of the path that a signature covers.
     */
    public function payload(ImageUrl $url): string;

    /**
     * @throws InvalidImageUrl when the path isn't in the codec's syntax, or not in its canonical form
     */
    public function decode(string $path): ImageUrl;
}
