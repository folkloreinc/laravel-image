<?php

namespace Folklore\Image\Sources;

use finfo;
use Folklore\Image\Contracts\ImageDataHandler;
use Folklore\Image\Exception\InvalidPathException;
use Illuminate\Filesystem\FilesystemAdapter;
use Imagine\Image\ImageInterface;
use League\Flysystem\Local\LocalFilesystemAdapter;

class FilesystemSource extends AbstractSource
{
    public function pathExists($path)
    {
        try {
            $fullPath = $this->getFullPath($path);
        } catch (InvalidPathException $e) {
            return false;
        }

        return $this->existsOnDisk($fullPath);
    }

    public function getFilesFromPath($path)
    {
        $disk = $this->getDisk();

        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $isFile = ! empty($extension);
        $directory = $isFile ? pathinfo($path, PATHINFO_DIRNAME) : $path;

        $files = $disk->allFiles($directory);
        $images = $this->getImagesFromFiles($files, $path);

        return $images;
    }

    public function getFormatFromPath($path)
    {
        $fullPath = $this->getFullPath($path);
        $disk = $this->getDisk();
        $localPath = $this->getLocalPath($fullPath);
        if ($localPath !== null) {
            return parent::getFormatFromPath($localPath);
        }

        $cache = data_get($this->config, 'cache', false);
        $existsCache = $cache ? $this->existsOnCache($fullPath) : false;
        if ($existsCache) {
            $cachePath = data_get($this->config, 'cache_path', null);
            if ($cachePath) {
                $cacheFullPath = $this->getCacheFullPath($fullPath);

                return parent::getFormatFromPath($cacheFullPath);
            } else {
                $cacheKey = $this->getCacheKey($fullPath);
                $content = app('cache')->get($cacheKey);

                return $this->getFormatFromContent($content);
            }
        }

        $content = $disk->get($fullPath);

        return $this->getFormatFromContent($content);
    }

    public function openFromPath($path)
    {
        $fullPath = $this->getFullPath($path);
        $disk = $this->getDisk();

        $localPath = $this->getLocalPath($fullPath);
        if ($localPath !== null) {
            return $this->imagine->open($localPath);
        }

        $cache = data_get($this->config, 'cache', false);
        $existsCache = $cache ? $this->existsOnCache($fullPath) : false;

        $stream = null;
        $content = null;
        $pathToOpen = null;
        if ($existsCache) {
            $cachePath = data_get($this->config, 'cache_path', null);
            if ($cachePath) {
                $pathToOpen = $this->getCacheFullPath($fullPath);
            } else {
                $cacheKey = $this->getCacheKey($fullPath);
                $content = app('cache')->get($cacheKey);
            }
        } else {
            $stream = $disk->readStream($fullPath);
            if ($cache) {
                $this->saveToCache($fullPath, $stream);
                rewind($stream);
            }
        }

        if ($stream) {
            return $this->imagine->read($stream);
        } elseif ($content) {
            return $this->imagine->load($content);
        } else {
            return $this->imagine->open($pathToOpen);
        }
    }

    public function saveToPath(ImageInterface $image, $path)
    {
        $fullPath = $this->getFullPath($path);
        $disk = $this->getDisk();

        $localPath = $this->getLocalPath($fullPath);
        if ($localPath !== null) {
            return app(ImageDataHandler::class)->save($image, $localPath);
        }

        $format = pathinfo($fullPath, \PATHINFO_EXTENSION);
        $content = app(ImageDataHandler::class)->get($image, $format);

        $disk->put($fullPath, $content);

        $cache = data_get($this->config, 'cache', false);
        if ($cache) {
            $this->saveToCache($fullPath, $content);
        }

        return $image;
    }

    public function getDisk()
    {
        $disk = $this->config['disk'];

        return $disk === 'cloud' ? app('filesystem')->cloud() : app('filesystem')->disk($disk);
    }

    /**
     * The absolute path of a file on a local disk, so it can be read in place,
     * or null for other disks.
     */
    protected function getLocalPath(string $fullPath): ?string
    {
        $disk = $this->getDisk();
        if ($disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            return $disk->path($fullPath);
        }

        return null;
    }

    protected function getFullPath($path)
    {
        $prefixPath = data_get($this->config, 'path', '/');
        $normalizedPath = $this->normalizePath($path);
        if ($normalizedPath === null) {
            throw new InvalidPathException('Path ['.$path.'] is outside the source root.');
        }

        return rtrim($prefixPath, '/').'/'.$normalizedPath;
    }

    protected function getCacheFullPath($path)
    {
        $prefix = data_get($this->config, 'cache_path', null);
        $cachePath = $this->getCachePath($path);
        $extension = pathinfo($path, \PATHINFO_EXTENSION);

        return rtrim($prefix, '/').'/'.$cachePath.(empty($extension) ? '' : ('.'.$extension));
    }

    protected function getCachePath($path)
    {
        $key = md5($path).'_'.sha1($path);

        return 'image/'.preg_replace('/^([0-9a-z]{2})([0-9a-z]{2})/i', '$1/$2/', $key);
    }

    protected function getCacheKey($path)
    {
        $cachePath = $this->getCachePath($path);

        return preg_replace('/[^a-zA-Z0-9]+/i', '_', $cachePath);
    }

    protected function existsOnCache($path)
    {
        $cachePath = data_get($this->config, 'cache_path', null);
        if ($cachePath) {
            return file_exists($this->getCacheFullPath($path));
        }

        $cacheKey = $this->getCacheKey($path);

        return app('cache')->has($cacheKey);
    }

    protected function existsOnDisk($path)
    {
        $disk = $this->getDisk();

        return $disk->exists($path);
    }

    protected function getMimeFromContent($content)
    {
        $finfo = new finfo(FILEINFO_MIME);

        return $finfo->buffer($content);
    }

    protected function getFormatFromContent($content)
    {
        $mime = $this->getMimeFromContent($content);
        preg_match('/image\/([a-z\-]+)/i', $mime, $matches);

        switch ($matches[1]) {
            case 'jpg':
            case 'jpeg':
                return 'jpeg';
                break;
            case 'png':
                return 'png';
                break;
        }

        return $matches[1];
    }

    protected function saveToCache($path, $contents)
    {
        $cachePath = data_get($this->config, 'cache_path', null);
        $cacheMode = data_get($this->config, 'cache_mode', 0755);
        if ($cachePath) {
            $filesystem = app('files');
            $fullPath = $this->getCacheFullPath($path);
            $directory = pathinfo($fullPath, PATHINFO_DIRNAME);
            if (! $filesystem->isDirectory($directory)) {
                $filesystem->makeDirectory($directory, $cacheMode, true, true);
            }
            $filesystem->put($fullPath, $contents);
        } else {
            // A cache store can't serialize a stream
            if (is_resource($contents)) {
                $contents = stream_get_contents($contents);
            }
            $cacheKey = $this->getCacheKey($path);
            $cacheExpiration = data_get($this->config, 'cache_expiration', -1);
            if ($cacheExpiration === -1) {
                app('cache')->forever($cacheKey, $contents);
            } else {
                app('cache')->put($cacheKey, $contents, $cacheExpiration);
            }
        }
    }
}
