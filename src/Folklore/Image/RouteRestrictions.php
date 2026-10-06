<?php

namespace Folklore\Image;

/**
 * Checks the filters requested in an image URL against the route options
 * (`allow_size`, `allow_filters`, `disallow_filters`) and the size limits.
 */
class RouteRestrictions
{
    /**
     * Filter keys that describe the output size. `allow_size` governs them, and they
     * don't count as filters for `allow_filters`, `disallow_filters` or `max_filters`.
     */
    protected const SIZE_KEYS = ['width', 'height', 'crop'];

    protected $defaults;

    /**
     * @param  array  $defaults  The global settings, from `image.restrictions`
     */
    public function __construct(array $defaults = [])
    {
        $this->defaults = $defaults;
    }

    /**
     * The settings for a route: the global ones, overridden by the route's `restrictions`.
     */
    public function settings(array $routeConfig): array
    {
        return array_merge([
            'mode' => 'log',
            'max_width' => null,
            'max_height' => null,
            'max_pixels' => null,
            'max_filters' => null,
        ], $this->defaults, (array) data_get($routeConfig, 'restrictions', []));
    }

    /**
     * Whether violations make the request fail (`enforce`) instead of being logged (`log`).
     */
    public function isEnforced(array $routeConfig): bool
    {
        return data_get($this->settings($routeConfig), 'mode') === 'enforce';
    }

    /**
     * The restrictions a request breaks, as human-readable messages.
     *
     * @param  array  $routeConfig  The `image` config of the route
     * @param  array  $filters  The filters parsed from the URL
     * @return string[]
     */
    public function violations(array $routeConfig, array $filters): array
    {
        $settings = $this->settings($routeConfig);
        $violations = [];

        $keys = array_keys($filters);
        $sizeKeys = array_values(array_intersect($keys, self::SIZE_KEYS));
        $filterKeys = array_values(array_diff($keys, self::SIZE_KEYS));

        if (data_get($routeConfig, 'allow_size', true) === false && count($sizeKeys) > 0) {
            $violations[] = 'A size is not allowed on this route.';
        }

        $allowFilters = data_get($routeConfig, 'allow_filters', true);
        if ($allowFilters === false && count($filterKeys) > 0) {
            $violations[] = 'Filters are not allowed on this route: '.implode(', ', $filterKeys).'.';
        } elseif (is_array($allowFilters)) {
            $denied = array_diff($filterKeys, $allowFilters);
            if (count($denied) > 0) {
                $violations[] = 'Filters not in allow_filters: '.implode(', ', $denied).'.';
            }
        }

        $disallowFilters = data_get($routeConfig, 'disallow_filters', false);
        if (is_array($disallowFilters)) {
            $denied = array_intersect($filterKeys, $disallowFilters);
            if (count($denied) > 0) {
                $violations[] = 'Filters in disallow_filters: '.implode(', ', $denied).'.';
            }
        }

        $width = isset($filters['width']) ? (int) $filters['width'] : null;
        $height = isset($filters['height']) ? (int) $filters['height'] : null;

        if ($settings['max_width'] !== null && $width !== null && $width > $settings['max_width']) {
            $violations[] = "Width {$width} is over max_width ({$settings['max_width']}).";
        }
        if ($settings['max_height'] !== null && $height !== null && $height > $settings['max_height']) {
            $violations[] = "Height {$height} is over max_height ({$settings['max_height']}).";
        }
        if ($settings['max_pixels'] !== null && $width !== null && $height !== null
            && $width * $height > $settings['max_pixels']) {
            $violations[] = 'Size '.$width.'x'.$height.' is over max_pixels ('.$settings['max_pixels'].').';
        }
        if ($settings['max_filters'] !== null && count($filterKeys) > $settings['max_filters']) {
            $violations[] = count($filterKeys)." filters is over max_filters ({$settings['max_filters']}).";
        }

        return $violations;
    }
}
