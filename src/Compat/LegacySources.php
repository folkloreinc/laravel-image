<?php

declare(strict_types=1);

namespace Folklore\Image\Compat;

use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Sources\DiskResolver;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * The 1.x sources of the legacy route, as `disk` resolvers (docs/decisions/0006-sources-and-resolvers.md):
 *
 * - `local`: the files under a `path`, such as `public_path()`, through a local disk named
 *   `image_legacy_{source}`, added to the config when it isn't there;
 * - `filesystem`: the files of a Laravel `disk`, under a `path`.
 *
 * They aren't resolvers of the 2.x URLs: only the legacy route reads them.
 *
 * @internal
 */
final readonly class LegacySources
{
    public function __construct(
        private LegacyConfig $legacy,
        private Repository $config,
        private FilesystemFactory $filesystems,
        private Cache $cache,
    ) {}

    /**
     * The resolver of a 1.x source, or of the default source.
     *
     * @throws InvalidImageConfig when there is no such source, or its driver isn't supported
     */
    public function resolver(?string $source = null): DiskResolver
    {
        $source ??= $this->legacy->defaultSource();
        $options = $this->legacy->source($source);
        $name = self::resolverName($source);
        $path = $options['path'] ?? null;
        if ($path !== null && ! is_string($path)) {
            throw new InvalidImageConfig("image.sources.{$source}.path is a path.");
        }

        switch ($options['driver'] ?? 'local') {
            case 'local':
                $disk = "image_legacy_{$name}";
                $root = $path ?? (function_exists('public_path') ? public_path() : throw new InvalidImageConfig("image.sources.{$source}.path is a path."));
                if ($this->config->get("filesystems.disks.{$disk}.root") !== $root) {
                    $this->config->set("filesystems.disks.{$disk}", ['driver' => 'local', 'root' => $root, 'throw' => true]);
                }

                return new DiskResolver($this->filesystems, $this->cache, $name, $disk);

            case 'filesystem':
                $disk = $options['disk'] ?? null;
                if (! is_string($disk)) {
                    throw new InvalidImageConfig("image.sources.{$source}.disk is the name of a disk.");
                }

                return new DiskResolver($this->filesystems, $this->cache, $name, $disk, $path ?? '');

            default:
                throw new InvalidImageConfig("image.sources.{$source}: 2.x reads the local and filesystem sources of 1.x only.");
        }
    }

    /**
     * The name of a 1.x source as a resolver's: lowercase letters, digits and underscores.
     */
    public static function resolverName(string $source): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($source)), '_');

        return preg_match('/^[a-z]/', $name) === 1 ? substr($name, 0, 32) : substr("source_{$name}", 0, 32);
    }
}
