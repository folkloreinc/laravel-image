<?php

declare(strict_types=1);

namespace Folklore\Image\Contracts;

/**
 * Signs URL payloads (UrlCodec::payload()), which include the expiry of URLs that expire
 * (docs/decisions/0005-secure-by-default.md). Checking the expiry is the caller's job.
 */
interface Signer
{
    /**
     * The signature of a payload, in base64url characters, with the current key.
     */
    public function sign(string $payload): string;

    /**
     * Whether the signature is valid for the payload with any of the keys, so keys can rotate.
     * Comparisons take constant time.
     */
    public function verify(string $payload, string $signature): bool;
}
