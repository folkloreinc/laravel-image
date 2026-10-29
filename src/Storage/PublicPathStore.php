<?php

declare(strict_types=1);

namespace Folklore\Image\Storage;

use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\DiskResolver;
use RuntimeException;

/**
 * The cache of the legacy route, as 1.x's `cache_path` (docs/decisions/0003-delivery.md): each
 * image is written at the path it was requested at, under a directory such as `public_path()`,
 * so the web server serves it as a static file on the next requests.
 *
 * @internal
 */
final readonly class PublicPathStore
{
    private string $directory;

    /**
     * @param  int  $mode  the mode of the directories it creates
     */
    public function __construct(
        string $directory,
        private int $mode = 0755,
    ) {
        $this->directory = rtrim($directory, '/');
    }

    /**
     * The file of a request path, when it is stored.
     *
     * @throws SourceNotFound when the path leaves the directory
     */
    public function get(string $path): ?string
    {
        $file = $this->file($path);

        return is_file($file) ? $file : null;
    }

    /**
     * Writes an image at a request path, through a temporary file renamed into place, so a
     * concurrent request never reads a partial file.
     *
     * @throws SourceNotFound when the path leaves the directory
     * @throws RuntimeException when the file can't be written
     */
    public function put(string $path, RenderedImage $image): string
    {
        $file = $this->file($path);
        $directory = dirname($file);

        if (! is_dir($directory) && ! @mkdir($directory, $this->mode, true) && ! is_dir($directory)) {
            throw new RuntimeException("The cache directory {$directory} can't be created.");
        }

        $temporary = $file.'.'.bin2hex(random_bytes(6)).'.tmp';
        if (@file_put_contents($temporary, $image->contents) === false || ! @rename($temporary, $file)) {
            @unlink($temporary);

            throw new RuntimeException("The cached image {$file} can't be written.");
        }

        return $file;
    }

    /**
     * @throws SourceNotFound when the path leaves the directory
     */
    private function file(string $path): string
    {
        $relative = DiskResolver::normalize($path);
        if ($relative === null || $relative !== trim($path, '/')) {
            throw new SourceNotFound("The path {$path} is outside the cache directory.");
        }

        return "{$this->directory}/{$relative}";
    }
}
