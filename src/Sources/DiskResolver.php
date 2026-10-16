<?php

declare(strict_types=1);

namespace Folklore\Image\Sources;

use finfo;
use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Exceptions\InvalidImageSource;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * The `disk` resolver (docs/decisions/0006-sources-and-resolvers.md): images on a Laravel disk,
 * under an optional root. A reference is a path (`uploads/photo.jpg`), or the path prefixed
 * with the resolver's name (`disk://uploads/photo.jpg`); the key is the normalized path.
 *
 * - **Containment**, as in 1.1: a reference can't leave the root, and a URL key must already
 *   be canonical (no empty, `.` or `..` segments). The check is lexical, so symbolic links
 *   inside the root keep working.
 * - **Version:** a hash of the file's last modification time, or of its ETag on S3-compatible
 *   disks, cached for `$versionTtl` seconds. A replaced original gets new URLs once it expires.
 * - **Metadata** (the upright width and height, the mime type, the EXIF orientation) is read
 *   once per version of the file, and cached.
 */
final readonly class DiskResolver implements SourceResolver
{
    /** How much of a remote file is read to find its dimensions, before reading all of it. */
    private const int HEAD_BYTES = 512 * 1024;

    private string $root;

    /**
     * @param  string  $name  the resolver's name in URLs: `disk`, or another one for another disk
     * @param  string  $disk  the Laravel disk of the originals
     * @param  string  $root  a directory of the disk that references can't leave
     * @param  int  $versionTtl  how long a file's version is cached, in seconds
     */
    public function __construct(
        private FilesystemFactory $filesystems,
        private Cache $cache,
        private string $name = 'disk',
        private string $disk = 'public',
        string $root = '',
        private int $versionTtl = 300,
    ) {
        if (! ImageSource::isValidResolverName($name)) {
            throw new InvalidImageSource("Invalid resolver name: {$name}. Use lowercase letters, digits and underscores.");
        }

        $this->root = trim($root, '/');
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Paths, and paths prefixed with `{name}://`. Other URLs (`media://…`, `https://…`) are
     * left to their resolvers.
     */
    public function supports(mixed $reference): bool
    {
        if (! is_string($reference) || $reference === '') {
            return false;
        }

        return str_starts_with($reference, "{$this->name}://") || preg_match('#^[a-z][a-z0-9+.-]*://#i', $reference) !== 1;
    }

    public function resolve(mixed $reference): ImageSource
    {
        if (! is_string($reference) || ! $this->supports($reference)) {
            throw new UnsupportedReference("The {$this->name} resolver only supports paths.");
        }

        $path = str_starts_with($reference, "{$this->name}://") ? substr($reference, strlen("{$this->name}://")) : $reference;
        $key = self::normalize($path);
        if ($key === null) {
            throw new SourceNotFound("The path {$path} is outside the {$this->name} source.");
        }

        return $this->resolveKey($key);
    }

    public function resolveKey(string $key): ImageSource
    {
        if (! ImageSource::isValidKey($key)) {
            throw new SourceNotFound("Invalid key for the {$this->name} source.");
        }

        $path = $this->root === '' ? $key : "{$this->root}/{$key}";
        $disk = $this->filesystems->disk($this->disk);
        $cacheKey = 'folklore-image:'.$this->name.':'.hash('sha256', "{$this->disk}\0{$path}");

        $version = $this->cache->remember("{$cacheKey}:version", $this->versionTtl, fn (): string => $this->version($disk, $path));
        $metadata = $this->cache->rememberForever("{$cacheKey}:{$version}", fn (): array => $this->metadata($disk, $path));

        return new ImageSource(
            resolver: $this->name,
            key: $key,
            version: $version,
            disk: $this->disk,
            path: $path,
            width: $metadata['width'],
            height: $metadata['height'],
            mime: $metadata['mime'],
            orientation: $metadata['orientation'],
        );
    }

    /**
     * A relative path with its `.` and `..` segments resolved, or null when it leaves the root.
     */
    public static function normalize(string $path): ?string
    {
        if (str_contains($path, "\0")) {
            return null;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * The first 8 hex characters of a hash of the ETag (S3-compatible disks) or of the last
     * modification time (other disks).
     */
    private function version(Filesystem $disk, string $path): string
    {
        try {
            if ($disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof AwsS3V3Adapter) {
                $etag = $disk->checksum($path, ['checksum_algo' => 'etag']);
                $value = is_string($etag) && $etag !== '' ? "etag:{$etag}" : throw new SourceNotFound("No ETag for {$path}.");
            } else {
                $value = 'mtime:'.$disk->lastModified($path);
            }
        } catch (FilesystemException $exception) {
            throw new SourceNotFound("The {$this->name} source {$path} is missing.", previous: $exception);
        }

        return substr(hash('sha256', $value), 0, 8);
    }

    /**
     * Reads the dimensions, the mime type and the EXIF orientation of a file. A local file is
     * read in place; a remote one is read up to HEAD_BYTES, or whole when that isn't enough.
     *
     * @return array{width: ?int, height: ?int, mime: ?string, orientation: ?int}
     */
    private function metadata(Filesystem $disk, string $path): array
    {
        if ($disk instanceof FilesystemAdapter && $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            $file = $disk->path($path);
            $info = @getimagesize($file);
            $head = (string) @file_get_contents($file, length: self::HEAD_BYTES);
            $exifSource = $file;
        } else {
            [$head, $info] = $this->readHead($disk, $path);
            $exifSource = 'data://application/octet-stream;base64,'.base64_encode($head);
        }

        $mime = $info === false ? null : $info['mime'];
        if ($mime === null || $mime === '' || $mime === 'application/octet-stream') {
            $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($head);
            $mime = is_string($detected) && $detected !== '' ? $detected : null;
        }

        $orientation = $mime === 'image/jpeg' ? self::orientation($exifSource) : null;
        [$width, $height] = $info === false || $info[0] < 1 || $info[1] < 1 ? [null, null] : [$info[0], $info[1]];
        if ($width !== null && $height !== null && $orientation !== null && $orientation >= 5) {
            [$width, $height] = [$height, $width];
        }

        return ['width' => $width, 'height' => $height, 'mime' => $mime, 'orientation' => $orientation];
    }

    /**
     * @return array{string, array{0: int, 1: int, mime: string}|false}
     */
    private function readHead(Filesystem $disk, string $path): array
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            throw new SourceNotFound("The {$this->name} source {$path} can't be read.");
        }

        try {
            $head = (string) stream_get_contents($stream, self::HEAD_BYTES);
            $info = @getimagesizefromstring($head);
            if ($info === false && ! feof($stream)) {
                $head .= (string) stream_get_contents($stream);
                $info = @getimagesizefromstring($head);
            }
        } finally {
            fclose($stream);
        }

        return [$head, $info];
    }

    private static function orientation(string $file): ?int
    {
        if (! function_exists('exif_read_data')) {
            return null;
        }

        $exif = @exif_read_data($file);
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? null) : null;

        return is_int($orientation) && $orientation >= 1 && $orientation <= 8 ? $orientation : null;
    }
}
