<?php

declare(strict_types=1);

namespace Folklore\Image\Compat;

use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Urls\PresetReference;
use Illuminate\Contracts\Config\Repository;

/**
 * The 1.x keys of the `image` config that the compatibility layer still reads
 * (docs/decisions/0007-compatibility-layer.md), where 1.x had them, with 1.x's defaults:
 *
 * - `url.format`, `url.filters_format`, `url.filter_format`, `url.filter_separator` and
 *   `url.placeholders_patterns`: the template of 1.x URLs, next to the 2.x `url.host` and
 *   `url.prefix`;
 * - `source` and `sources`: where the legacy route reads images;
 * - `restrictions`: the limits of the legacy route, logged or enforced;
 * - `filters`: the named filters, as arrays of options;
 * - `sizes`: read as presets when `image.presets` is not set;
 * - `routes`: the routes file of `Image::routes()`, loaded when `routes.map` is set.
 *
 * @internal
 */
final readonly class LegacyConfig
{
    /** The keys of the 1.x template in `image.url`. */
    public const array URL_KEYS = ['format', 'filters_format', 'filter_format', 'filter_separator', 'placeholders_patterns'];

    /** The 1.x keys of the config that 2.x doesn't have. */
    public const array KEYS = ['sizes', 'filters', 'source', 'sources', 'routes', 'restrictions', 'driver', 'auto_orient', 'strip_metadata', 'utils'];

    /** The 1.x defaults of `image.restrictions`. */
    public const array RESTRICTIONS = ['mode' => 'log', 'max_width' => 5000, 'max_height' => 5000, 'max_pixels' => 25_000_000, 'max_filters' => 10];

    public function __construct(
        private Repository $config,
    ) {}

    /**
     * Whether the config is a 1.x config, or has 1.x keys.
     */
    public function isLegacy(): bool
    {
        foreach (self::KEYS as $key) {
            if ($this->config->has("image.{$key}")) {
                return true;
            }
        }

        return $this->urlOptions() !== [];
    }

    /**
     * Whether the compatibility layer binds the `image` key of the container, as 1.x did:
     * `image.bind_image`, or, when it is null, for a 1.x config.
     */
    public function bindsImage(): bool
    {
        $bind = $this->config->get('image.bind_image');

        return match (true) {
            $bind === null => $this->isLegacy(),
            is_bool($bind) => $bind,
            default => throw new InvalidImageConfig('image.bind_image is true, false or null.'),
        };
    }

    /**
     * The 1.x URL template that the config sets, without the defaults.
     *
     * @return array<string, mixed>
     */
    public function urlOptions(): array
    {
        $url = $this->config->get('image.url', []);

        return is_array($url) ? array_intersect_key($url, array_flip(self::URL_KEYS)) : [];
    }

    /**
     * The name of the default 1.x source.
     */
    public function defaultSource(): string
    {
        $source = $this->config->get('image.source', 'public');

        return is_string($source) ? $source : throw new InvalidImageConfig('image.source is the name of a source.');
    }

    /**
     * The 1.x source of this name: `local` (a `path`) or `filesystem` (a `disk` and a `path`).
     *
     * @return array<string, mixed>
     */
    public function source(string $name): array
    {
        $sources = $this->config->get('image.sources');
        $source = is_array($sources) ? ($sources[$name] ?? null) : self::defaultSources()[$name] ?? null;

        if (! is_array($source)) {
            throw new InvalidImageConfig("image.sources.{$name}: there is no 1.x source of this name.");
        }

        return self::options($source);
    }

    /**
     * The global 1.x restrictions, over their defaults.
     *
     * @return array<string, mixed>
     */
    public function restrictions(): array
    {
        return [...self::RESTRICTIONS, ...self::options($this->config->get('image.restrictions', []))];
    }

    /**
     * The 1.x `image.filters` config.
     *
     * @return array<mixed>
     */
    public function filters(): array
    {
        $filters = $this->config->get('image.filters', []);

        return is_array($filters) ? $filters : [];
    }

    /**
     * The 1.x `image.routes` config: the routes file (`map`), and the group of its routes.
     *
     * @return array<string, mixed>
     */
    public function routes(): array
    {
        return self::options($this->config->get('image.routes', []));
    }

    /**
     * The presets of `image.sizes`, the named sizes of the project generator and of
     * laravel-folklore 1.x, by name:
     *
     *     ['id' => 'thumbnail', 'width' => 200, 'height' => 200]            cropped to 200×200
     *     ['id' => 'large', 'maxWidth' => 1400, 'maxHeight' => 1400]        inside 1400×1400
     *     ['id' => 'facebook', 'width' => 1200, 'height' => 630, 'crop' => true]
     *
     * A list of sizes with an `id`, or sizes by name. `width` and `height` crop unless `crop` is
     * false; `maxWidth` and `maxHeight` fit inside unless `crop` is true. Sizes without options,
     * such as `original`, aren't presets.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws InvalidImageConfig for a size that isn't a valid preset
     */
    public function sizePresets(): array
    {
        $sizes = $this->config->get('image.sizes', []);
        $presets = [];

        foreach (is_array($sizes) ? $sizes : [] as $key => $size) {
            if (! is_array($size)) {
                continue;
            }
            $name = is_string($key) ? $key : ($size['id'] ?? null);
            if (! is_string($name)) {
                throw new InvalidImageConfig('image.sizes: each size has an id.');
            }

            $filter = self::sizeFilter($size);
            if ($filter === []) {
                continue;
            }
            if (! PresetReference::isValidName($name)) {
                throw new InvalidImageConfig("image.sizes.{$name}: a preset name has lowercase letters, digits, - and _.");
            }

            try {
                $presets[$name] = LegacyFilters::presetDefinition($filter);
            } catch (InvalidImageSpec $exception) {
                throw new InvalidImageConfig("image.sizes.{$name}: {$exception->getMessage()}", previous: $exception);
            }
        }

        return $presets;
    }

    /**
     * The 1.x filter of a named size.
     *
     * @param  array<mixed>  $size
     * @return array<string, mixed>
     */
    public static function sizeFilter(array $size): array
    {
        $exact = isset($size['width']) || isset($size['height']);
        $width = $size['width'] ?? $size['maxWidth'] ?? null;
        $height = $size['height'] ?? $size['maxHeight'] ?? null;
        if ($width === null && $height === null) {
            return [];
        }

        $crop = $size['crop'] ?? ($exact && $width !== null && $height !== null);

        return array_filter(['width' => $width, 'height' => $height, 'crop' => $crop === true ? true : null], fn ($value) => $value !== null);
    }

    /**
     * The options of an array of options, by name: other values and keys are left out.
     *
     * @return array<string, mixed>
     */
    public static function options(mixed $options): array
    {
        $named = [];
        foreach (is_array($options) ? $options : [] as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }

        return $named;
    }

    /**
     * The 1.x default sources.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function defaultSources(): array
    {
        return [
            'public' => ['driver' => 'local', 'path' => function_exists('public_path') ? public_path() : null],
            'cloud' => ['driver' => 'filesystem', 'disk' => 'public', 'path' => null],
        ];
    }
}
