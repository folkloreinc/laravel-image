<?php

namespace Folklore\Image;

use Folklore\Image\Contracts\CacheManager as CacheManagerContract;
use Folklore\Image\Contracts\ImageDataHandler;
use Illuminate\Filesystem\Filesystem;
use Imagine\Image\ImageInterface;

class CacheManager implements CacheManagerContract
{
    protected $filesystem;

    protected $dataHandler;

    public function __construct(Filesystem $filesystem, ImageDataHandler $dataHandler)
    {
        $this->filesystem = $filesystem;
        $this->dataHandler = $dataHandler;
    }

    /**
     * Save the image in the cache directory, unless it is already there.
     *
     * @param  array  $options  Encoding options, such as a route's `quality`. Not part of
     *                          the contract, so custom cache managers keep working without it.
     * @return string The full path of the cached file
     */
    public function put(ImageInterface $image, $path, $directory = null, $mode = null, array $options = [])
    {
        if (is_null($directory)) {
            $directory = public_path();
        }

        $fullPath = rtrim($directory, '/').'/'.ltrim($path, '/');
        $directory = dirname($fullPath);

        // If the cache file exists, serve this file.
        if ($this->filesystem->exists($fullPath)) {
            return $fullPath;
        }

        // Check if cache directory is writable and create the directory if
        // it doesn't exists.
        $directoryExists = $this->filesystem->exists($directory);
        if ($directoryExists && ! $this->filesystem->isWritable($directory)) {
            throw new \Exception('Destination is not writeable');
        }
        if (! $directoryExists) {
            $this->filesystem->makeDirectory($directory, $mode ?? 0755, true, true);
        }

        $this->dataHandler->save($image, $fullPath, $options);

        return $fullPath;
    }
}
