<?php

declare(strict_types=1);

namespace Folklore\Image\Storage;

use Closure;
use Folklore\Image\Specs\Format;

/**
 * A derivative read from a store. Its contents are only read when asked for.
 */
final readonly class StoredDerivative
{
    /**
     * @param  Closure(): resource  $stream  opens a stream of the contents
     */
    public function __construct(
        public DerivativeKey $key,
        public int $length,
        public ?int $lastModified,
        private Closure $stream,
    ) {}

    public function format(): Format
    {
        return $this->key->format();
    }

    public function mime(): string
    {
        return $this->key->format()->mime();
    }

    /**
     * A stream of the contents, for responses. The caller closes it.
     *
     * @return resource
     */
    public function stream()
    {
        return ($this->stream)();
    }

    public function contents(): string
    {
        $stream = $this->stream();

        try {
            return (string) stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    /**
     * A strong ETag: a derivative's contents never change for its key.
     */
    public function etag(): string
    {
        return '"'.substr(hash('sha256', $this->key->path()), 0, 32).'"';
    }
}
