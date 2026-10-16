<?php

declare(strict_types=1);

namespace Folklore\Image\Sources;

use Folklore\Image\Exceptions\InvalidImageSource;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Size;
use Folklore\Image\Specs\Transform;

/**
 * An original image, as a resolver describes it (docs/decisions/0006-sources-and-resolvers.md):
 * everything URLs and variants need, without opening the file.
 */
final readonly class ImageSource
{
    /**
     * @param  string  $resolver  the name of the resolver that resolves the key back to this source
     * @param  string  $key  the opaque part of URLs: a media id (`med_01JAB3…`), or the path for a disk source
     * @param  string|null  $version  changes when the original changes, so its URLs change too: a hash of the
     *                                file, of its modification time or the media's own version
     * @param  string|null  $disk  the Laravel disk of the original, read only when rendering
     * @param  string|null  $path  the path of the original on that disk
     * @param  int|null  $width  the width of the upright original (after its EXIF orientation), when known
     * @param  int|null  $height  the height of the upright original, when known
     * @param  int|null  $orientation  the EXIF orientation of the original file, from 1 to 8, when known
     * @param  Focus|null  $focalPoint  the default focal point, from the media or its transform
     * @param  Transform|null  $transform  the default non-destructive edits (Panneau 5's crop, rotate and flip)
     * @param  string|null  $placeholder  a ThumbHash of the image, when the media library stores one
     * @param  bool  $private  private sources are only served through signed URLs that expire
     */
    public function __construct(
        public string $resolver,
        public string $key,
        public ?string $version = null,
        public ?string $disk = null,
        public ?string $path = null,
        public ?int $width = null,
        public ?int $height = null,
        public ?string $mime = null,
        public ?int $orientation = null,
        public ?Focus $focalPoint = null,
        public ?Transform $transform = null,
        public ?string $placeholder = null,
        public bool $private = false,
    ) {
        if (! self::isValidResolverName($resolver)) {
            throw new InvalidImageSource("Invalid resolver name: {$resolver}. Use lowercase letters, digits and underscores.");
        }
        if (! self::isValidKey($key)) {
            throw new InvalidImageSource("Invalid source key: {$key}.");
        }
        if ($version !== null && ! self::isValidVersion($version)) {
            throw new InvalidImageSource("Invalid source version: {$version}. Use up to 64 letters, digits, - and _.");
        }
        if (($width === null) !== ($height === null) || ($width !== null && $width < 1) || ($height !== null && $height < 1)) {
            throw new InvalidImageSource('A source has a positive width and height, or neither.');
        }
        if ($orientation !== null && ($orientation < 1 || $orientation > 8)) {
            throw new InvalidImageSource("An EXIF orientation is between 1 and 8, not {$orientation}.");
        }
    }

    /**
     * The size of the upright original, when the resolver knows it.
     */
    public function size(): ?Size
    {
        return $this->width === null || $this->height === null ? null : new Size($this->width, $this->height);
    }

    public function isPrivate(): bool
    {
        return $this->private;
    }

    public static function isValidResolverName(string $name): bool
    {
        return preg_match('/^[a-z][a-z0-9_]{0,31}$/', $name) === 1;
    }

    /**
     * Keys are paths of non-empty segments, without `.` or `..` segments, backslashes or control
     * characters, up to 1024 bytes. URLs encode each segment.
     */
    public static function isValidKey(string $key): bool
    {
        if ($key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x1F\x7F\\\\]/', $key) === 1) {
            return false;
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public static function isValidVersion(string $version): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $version) === 1;
    }
}
