<?php

namespace Folklore\Image\Sources;

use Folklore\Image\Contracts\Source;
use Folklore\Image\Contracts\UrlGenerator;
use Imagine\Image\ImagineInterface;

abstract class AbstractSource implements Source
{
    protected $imagine;

    protected $urlGenerator;

    protected $config;

    public function __construct(ImagineInterface $imagine, UrlGenerator $urlGenerator, $config)
    {
        $this->imagine = $imagine;
        $this->urlGenerator = $urlGenerator;
        $this->config = $config;
    }

    /**
     * Normalize a path relative to the source root, resolving `.` and `..` segments.
     *
     * The check is lexical on purpose: symbolic links inside the root (such as the
     * `public/storage` link from `php artisan storage:link`) keep working.
     *
     * @param  string  $path  The path requested from the source
     * @return string|null The normalized relative path, or null if it leaves the root
     */
    protected function normalizePath($path)
    {
        $path = (string) $path;
        if (str_contains($path, "\0")) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (count($segments) === 0) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    protected function getImagesFromFiles($files, $path = null)
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $isFile = ! empty($extension);
        $basename = $isFile ? pathinfo($path, PATHINFO_BASENAME) : null;
        $directory = $isFile ? pathinfo($path, PATHINFO_DIRNAME) : $path;
        $images = [];
        foreach ($files as $file) {
            if (! preg_match('#'.$this->urlGenerator->pattern().'#', $file)) {
                continue;
            }
            $parsedPath = $this->urlGenerator->parse($file);
            if ($isFile && $basename !== pathinfo($parsedPath['path'], PATHINFO_BASENAME)) {
                continue;
            }
            $images[] = rtrim(ltrim($directory, '.'), '/').'/'.ltrim(ltrim($file, './'), '/');
        }

        return $images;
    }

    /**
     * Get the format of an image
     *
     * @param  string  $path  The path to an image
     * @return string|null
     */
    public function getFormatFromPath($path)
    {
        $format = @exif_imagetype($path);
        switch ($format) {
            case IMAGETYPE_GIF:
                return 'gif';
                break;
            case IMAGETYPE_JPEG:
                return 'jpeg';
                break;
            case IMAGETYPE_PNG:
                return 'png';
            case 18:
                return 'webp';
            case 19:
                return 'avif';
                break;
        }

        // HEIC has no image type either: check the content
        $mime = is_file($path) ? @mime_content_type($path) : false;
        if (in_array($mime, ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'], true)) {
            return 'heic';
        }

        if (preg_match('/\.svg$/', $format) === 1) {
            return 'svg';
        }

        return null;
    }
}
