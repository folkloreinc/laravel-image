<?php

declare(strict_types=1);

namespace Folklore\Image\Compat;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ArrayPreset;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Urls\PresetReference;

/**
 * The 1.x filters (docs/decisions/0007-compatibility-layer.md): the named filters registered as
 * arrays of options, with `Image::filter('large', [...])` or in the 1.x `image.filters` config,
 * and the conversion of 1.x options to a spec:
 *
 * - `width` and `height` with `crop` (or a crop position, `top_left`) cover the box; without
 *   `crop`, the image fits inside it; one of them alone resizes proportionally;
 * - `rotate` is a rotation of the source, in multiples of 90 degrees;
 * - `format` is the output format;
 * - another name is a named filter, else a preset of that name, else an operation: `grayscale`,
 *   `blur(5)`. The renderer refuses operations it doesn't have.
 *
 * `upscale`, `auto_orient` and `memory_limit` are the route's, never the URL's: they are ignored.
 */
final class LegacyFilters
{
    /** Options of a route or of the config in 1.x, which filters can't set. */
    public const array ROUTE_OPTIONS = ['upscale', 'auto_orient', 'memory_limit'];

    /** The focal point of each 1.x crop position. */
    private const array POSITIONS = [
        'top' => [0.5, 0.0], 'bottom' => [0.5, 1.0], 'left' => [0.0, 0.5], 'right' => [1.0, 0.5],
        'top_left' => [0.0, 0.0], 'top_right' => [1.0, 0.0], 'bottom_left' => [0.0, 1.0], 'bottom_right' => [1.0, 1.0],
        'top_center' => [0.5, 0.0], 'bottom_center' => [0.5, 1.0], 'center_left' => [0.0, 0.5], 'center_right' => [1.0, 0.5],
    ];

    /** @var array<string, array<int|string, mixed>> */
    private array $filters = [];

    /**
     * @param  array<mixed>  $config  the 1.x `image.filters` config: arrays of options are named
     *                                filters; classes and closures were Imagine filters
     */
    public function __construct(
        private readonly PresetRegistry $presets,
        array $config = [],
    ) {
        foreach ($config as $name => $filter) {
            if (is_string($name) && is_array($filter)) {
                $this->filters[$name] = $filter;
            }
        }
    }

    /**
     * Registers a named filter, and the preset of the same name when its options make a spec
     * and its name is a preset's.
     *
     * @param  array<int|string, mixed>  $filter
     */
    public function register(string $name, array $filter): void
    {
        $this->filters[$name] = $filter;

        if (PresetReference::isValidName($name)) {
            try {
                $this->presets->register(ArrayPreset::fromArray($name, self::presetDefinition($filter)));
            } catch (InvalidImageSpec) {
                // A filter 2.x can't render, such as a rotation of 45 degrees, stays a 1.x filter.
            }
        }
    }

    /**
     * The options of a named filter.
     *
     * @return array<int|string, mixed>|null
     */
    public function get(string $name): ?array
    {
        return $this->filters[$name] ?? null;
    }

    /**
     * @return array<string, array<int|string, mixed>>
     */
    public function all(): array
    {
        return $this->filters;
    }

    /**
     * The definition of a preset (ImageSpec::fromArray()) from 1.x options.
     *
     * @param  array<int|string, mixed>  $filter
     * @return array<string, mixed>
     *
     * @throws InvalidImageSpec
     */
    public static function presetDefinition(array $filter): array
    {
        return self::convert($filter)->toArray();
    }

    /**
     * The spec of 1.x options, named filters already expanded, as 1.x rendered them. A name
     * that is a preset's starts from the preset's spec for this source.
     *
     * @param  array<int|string, mixed>  $filters
     *
     * @throws InvalidImageSpec when the options can't be rendered by 2.x
     */
    public function spec(array $filters, ?ImageSource $source = null): ImageSpec
    {
        return self::convert($filters, $this->presets, $source ?? new ImageSource('legacy', 'legacy'));
    }

    /**
     * @param  array<int|string, mixed>  $filters
     */
    private static function convert(array $filters, ?PresetRegistry $presets = null, ?ImageSource $source = null): ImageSpec
    {
        $options = [];
        $operations = [];
        $base = null;
        $crop = false;

        foreach ($filters as $name => $value) {
            if (is_int($name)) {
                [$name, $value] = [is_string($value) ? $value : throw new InvalidImageSpec('A 1.x filter is a name.'), true];
            }

            if (in_array($name, self::ROUTE_OPTIONS, true)) {
                continue;
            } elseif ($name === 'width' || $name === 'height') {
                $options[$name] = self::dimension($value);
            } elseif ($name === 'crop') {
                $crop = $value;
            } elseif ($name === 'rotate') {
                $options['rotate'] = self::rotation($value);
            } elseif ($name === 'format') {
                $options['format'] = self::format($value);
            } elseif ($name === 'resize') {
                [$options['width'], $options['height'], $crop] = self::resize($value);
            } elseif ($value === true && $source !== null && ($preset = $presets?->find($name)) !== null) {
                $base = $preset->spec($source);
            } else {
                $operations[strtolower($name)] = $value === true ? [] : self::arguments($name, $value);
            }
        }

        $options = array_filter($options, fn ($value) => $value !== null);
        if (isset($options['width'], $options['height'])) {
            $position = self::crop($crop);
            $options['fit'] = $position === false ? 'inside' : 'cover';
            if (is_array($position)) {
                $options['focus'] = $position;
            }
        }

        if ($base === null) {
            return ImageSpec::fromArray($operations === [] ? $options : [...$options, 'operations' => $operations]);
        }

        // A preset named in a URL, with the other options of the URL over its own.
        if ($operations !== []) {
            $options['operations'] = [...$base->operations, ...$operations];
        }

        return $options === [] ? $base : $base->with($options);
    }

    private static function dimension(mixed $value): ?int
    {
        return match (true) {
            $value === null, $value === '_', $value === '' => null,
            is_int($value) => $value,
            is_string($value) && ctype_digit($value) => (int) $value,
            default => throw new InvalidImageSpec('A 1.x width or height is a number.'),
        };
    }

    private static function rotation(mixed $value): int
    {
        $degrees = is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : null;

        return $degrees !== null && $degrees % 90 === 0
            ? $degrees
            : throw new InvalidImageSpec('Rotations are multiples of 90 degrees in 2.x, not '.(is_scalar($value) ? $value : get_debug_type($value)).'.');
    }

    private static function format(mixed $value): string
    {
        $value = is_string($value) ? strtolower($value) : '';
        $format = Format::tryFrom($value === 'jpg' ? 'jpeg' : $value);

        return $format !== null && $format !== Format::Auto ? $format->value : throw new InvalidImageSpec("Unknown format: {$value}.");
    }

    /**
     * The 1.x `resize` filter: an array of options, or `width,height,crop`.
     *
     * @return array{int|null, int|null, mixed}
     */
    private static function resize(mixed $value): array
    {
        if (is_string($value)) {
            $parts = explode(',', $value);
            $value = ['width' => $parts[0], 'height' => $parts[1] ?? null, 'crop' => $parts[2] ?? true];
        }

        return is_array($value)
            ? [self::dimension($value['width'] ?? null), self::dimension($value['height'] ?? null), $value['crop'] ?? false]
            : throw new InvalidImageSpec('The 1.x resize filter is an array of options.');
    }

    /**
     * False for no crop, null for a centered crop, or the focal point of a crop position.
     *
     * @return array{x: float, y: float}|false|null
     */
    private static function crop(mixed $crop): array|false|null
    {
        if ($crop === false || $crop === null || $crop === 'false' || $crop === '0' || $crop === 0) {
            return false;
        }
        if ($crop === true || $crop === 'true' || $crop === '1' || $crop === 1 || $crop === 'center' || $crop === 'center_center') {
            return null;
        }

        $position = is_string($crop) ? (self::POSITIONS[strtolower($crop)] ?? null) : null;

        return $position === null
            ? throw new InvalidImageSpec('Unknown 1.x crop position: '.(is_scalar($crop) ? $crop : get_debug_type($crop)).'.')
            : ['x' => $position[0], 'y' => $position[1]];
    }

    /**
     * @return list<string>
     */
    private static function arguments(string $name, mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');

        return array_map(fn ($argument) => is_scalar($argument)
            ? strtolower((string) $argument)
            : throw new InvalidImageSpec("Invalid argument for the 1.x {$name} filter."), array_values($values));
    }
}
