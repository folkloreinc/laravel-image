<?php

declare(strict_types=1);

namespace Folklore\Image\Testing;

use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Urls\ImageUrl;

/**
 * Wraps the codec, and records the URLs it encodes, for ImageFake.
 *
 * @internal
 */
final class RecordingUrlCodec implements UrlCodec
{
    /** @var list<ImageUrl> */
    private array $encoded = [];

    public function __construct(
        private readonly UrlCodec $codec,
    ) {}

    public function encode(ImageUrl $url): string
    {
        $path = $this->codec->encode($url);
        $this->encoded[] = $url;

        return $path;
    }

    public function payload(ImageUrl $url): string
    {
        return $this->codec->payload($url);
    }

    public function decode(string $path): ImageUrl
    {
        return $this->codec->decode($path);
    }

    /**
     * @return list<ImageUrl>
     */
    public function encoded(): array
    {
        return $this->encoded;
    }
}
