<?php

declare(strict_types=1);

namespace Folklore\Image\Storage;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * Keeps derivatives on any Laravel disk (docs/decisions/0003-delivery.md), under a directory,
 * at the paths of DerivativeKey: `derivatives/media/med_01JAB3QX/8c1f/w800,h450.webp`.
 *
 * Derivatives never change for their key, so they are written with a long-lived
 * `Cache-Control` for disks that serve them directly. Rendering takes a lock on the key
 * (RenderLock), so the store doesn't handle concurrent puts itself.
 */
final readonly class DiskStore implements DerivativeStore
{
    private string $path;

    /**
     * @param  string  $path  the directory of the derivatives on the disk
     * @param  bool  $public  whether the disk serves its files at its URLs, for redirects
     */
    public function __construct(
        private Filesystem $disk,
        string $path = 'derivatives',
        private bool $public = false,
    ) {
        $this->path = trim($path, '/');
    }

    public function get(DerivativeKey $key): ?StoredDerivative
    {
        $path = $this->pathOf($key);

        try {
            $length = $this->disk->size($path);
        } catch (FilesystemException) {
            return null;
        }

        return new StoredDerivative($key, $length, $this->lastModified($path), function () use ($path) {
            $stream = $this->disk->readStream($path);

            return is_resource($stream) ? $stream : throw new RuntimeException("The derivative {$path} can't be read.");
        });
    }

    public function put(DerivativeKey $key, RenderedImage $image): StoredDerivative
    {
        $path = $this->pathOf($key);
        $written = $this->disk->put($path, $image->contents, [
            'ContentType' => $image->mime(),
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);
        if ($written === false) {
            throw new RuntimeException("The derivative {$path} can't be written.");
        }

        $contents = $image->contents;

        return new StoredDerivative($key, $image->length(), time(), function () use ($contents) {
            $stream = fopen('php://memory', 'r+');
            if ($stream === false) {
                throw new RuntimeException('Could not open a memory stream.');
            }
            fwrite($stream, $contents);
            rewind($stream);

            return $stream;
        });
    }

    public function clear(ImageSource $source): int
    {
        $directory = $this->prefixed(DerivativeKey::directoryOf($source));
        $files = $this->disk->allFiles($directory);

        if ($files !== []) {
            $this->disk->delete($files);
        }
        $this->disk->deleteDirectory($directory);

        return count($files);
    }

    public function url(DerivativeKey $key): ?string
    {
        if (! $this->public) {
            return null;
        }

        try {
            return $this->disk->url($this->pathOf($key));
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * The path of a derivative on the disk.
     */
    public function pathOf(DerivativeKey $key): string
    {
        return $this->prefixed($key->path());
    }

    private function prefixed(string $path): string
    {
        return $this->path === '' ? $path : "{$this->path}/{$path}";
    }

    /**
     * The last modification time on local disks. Other disks would need another request.
     */
    private function lastModified(string $path): ?int
    {
        if (! $this->disk instanceof FilesystemAdapter || ! $this->disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return null;
        }

        try {
            return $this->disk->lastModified($path);
        } catch (FilesystemException) {
            return null;
        }
    }
}
