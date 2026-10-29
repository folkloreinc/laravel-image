<?php

declare(strict_types=1);

namespace Folklore\Image\Http;

use Folklore\Image\Compat\LegacyConfig;

/**
 * The 1.x restrictions of the legacy route: the route options `allow_size`, `allow_filters` and
 * `disallow_filters`, and the limits of `image.restrictions` with the route's own `restrictions`
 * over them. In `log` mode, 1.x's default, a request that breaks them is logged and served; in
 * `enforce` mode, it answers 404.
 *
 * @internal
 */
final readonly class LegacyRestrictions
{
    /** Filters of the output size: `allow_size` governs them, and they aren't filters. */
    private const array SIZE_KEYS = ['width', 'height', 'crop'];

    /**
     * @param  array<string, mixed>  $defaults  `image.restrictions`, with 1.x's defaults
     */
    public function __construct(
        private array $defaults,
    ) {}

    /**
     * The settings of a route: the global ones, overridden by the route's `restrictions`.
     *
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    public function settings(array $route): array
    {
        return [
            'mode' => 'log', 'max_width' => null, 'max_height' => null, 'max_pixels' => null, 'max_filters' => null,
            ...$this->defaults,
            ...LegacyConfig::options($route['restrictions'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $route
     */
    public function isEnforced(array $route): bool
    {
        return $this->settings($route)['mode'] === 'enforce';
    }

    /**
     * The restrictions a request breaks, as messages.
     *
     * @param  array<string, mixed>  $route  the options of the route
     * @param  array<string, mixed>  $filters  the filters of the URL
     * @return list<string>
     */
    public function violations(array $route, array $filters): array
    {
        $settings = $this->settings($route);
        $violations = [];

        $keys = array_keys($filters);
        $sizeKeys = array_values(array_intersect($keys, self::SIZE_KEYS));
        $filterKeys = array_values(array_diff($keys, self::SIZE_KEYS));

        if (($route['allow_size'] ?? true) === false && $sizeKeys !== []) {
            $violations[] = 'A size is not allowed on this route.';
        }

        $allowFilters = $route['allow_filters'] ?? true;
        if ($allowFilters === false && $filterKeys !== []) {
            $violations[] = 'Filters are not allowed on this route: '.implode(', ', $filterKeys).'.';
        } elseif (is_array($allowFilters)) {
            $denied = array_diff($filterKeys, self::names($allowFilters));
            if ($denied !== []) {
                $violations[] = 'Filters not in allow_filters: '.implode(', ', $denied).'.';
            }
        }

        $disallowFilters = $route['disallow_filters'] ?? false;
        if (is_array($disallowFilters)) {
            $denied = array_intersect($filterKeys, self::names($disallowFilters));
            if ($denied !== []) {
                $violations[] = 'Filters in disallow_filters: '.implode(', ', $denied).'.';
            }
        }

        $width = self::integer($filters['width'] ?? null);
        $height = self::integer($filters['height'] ?? null);
        $maxWidth = self::integer($settings['max_width']);
        $maxHeight = self::integer($settings['max_height']);
        $maxPixels = self::integer($settings['max_pixels']);
        $maxFilters = self::integer($settings['max_filters']);

        if ($maxWidth !== null && $width !== null && $width > $maxWidth) {
            $violations[] = "Width {$width} is over max_width ({$maxWidth}).";
        }
        if ($maxHeight !== null && $height !== null && $height > $maxHeight) {
            $violations[] = "Height {$height} is over max_height ({$maxHeight}).";
        }
        if ($maxPixels !== null && $width !== null && $height !== null && $width * $height > $maxPixels) {
            $violations[] = "Size {$width}x{$height} is over max_pixels ({$maxPixels}).";
        }
        if ($maxFilters !== null && count($filterKeys) > $maxFilters) {
            $violations[] = count($filterKeys)." filters is over max_filters ({$maxFilters}).";
        }

        return $violations;
    }

    /**
     * @param  array<mixed>  $names
     * @return list<string>
     */
    private static function names(array $names): array
    {
        return array_values(array_filter($names, is_string(...)));
    }

    private static function integer(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
