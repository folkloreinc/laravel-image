<?php

declare(strict_types=1);

namespace Folklore\Image\Testing;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\StoredDerivative;

/**
 * Keeps derivatives in memory, for the length of a request or a test. Image::fake() uses it,
 * so requests to the image route store nothing on disk.
 */
final class InMemoryDerivativeStore implements DerivativeStore
{
    /** @var array<string, array{key: DerivativeKey, contents: string}> */
    private array $derivatives = [];

    /**
     * @param  string|null  $url  the base of the URLs of url(), for tests of redirects; null gives none
     */
    public function __construct(
        private readonly ?string $url = null,
    ) {}

    public function get(DerivativeKey $key): ?StoredDerivative
    {
        $derivative = $this->derivatives[$key->path()] ?? null;

        return $derivative === null ? null : $this->stored($derivative['key'], $derivative['contents']);
    }

    public function put(DerivativeKey $key, RenderedImage $image): StoredDerivative
    {
        $this->derivatives[$key->path()] = ['key' => $key, 'contents' => $image->contents];

        return $this->stored($key, $image->contents);
    }

    public function clear(ImageSource $source): int
    {
        $directory = DerivativeKey::directoryOf($source).'/';
        $paths = array_filter(array_keys($this->derivatives), fn (string $path) => str_starts_with($path, $directory));

        foreach ($paths as $path) {
            unset($this->derivatives[$path]);
        }

        return count($paths);
    }

    public function url(DerivativeKey $key): ?string
    {
        return $this->url === null ? null : rtrim($this->url, '/').'/'.implode('/', array_map(rawurlencode(...), explode('/', $key->path())));
    }

    /**
     * The paths of the derivatives stored.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->derivatives);
    }

    private function stored(DerivativeKey $key, string $contents): StoredDerivative
    {
        return new StoredDerivative($key, strlen($contents), null, function () use ($contents) {
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new \RuntimeException('Could not open a memory stream.');
            }
            fwrite($stream, $contents);
            rewind($stream);

            return $stream;
        });
    }
}
