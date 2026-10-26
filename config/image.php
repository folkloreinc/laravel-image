<?php

declare(strict_types=1);

/*
 * A published copy of this file only needs the keys it changes: the package's defaults fill
 * in the others, nested keys included. Presets and lists replace the defaults.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Renderer
    |--------------------------------------------------------------------------
    |
    | The Intervention Image driver: "auto" uses libvips when the
    | intervention/image-driver-vips package is installed and libvips loads,
    | otherwise Imagick, otherwise GD. Set "vips", "imagick" or "gd" to
    | require one.
    |
    */

    'renderer' => [
        'driver' => env('IMAGE_DRIVER', 'auto'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality
    |--------------------------------------------------------------------------
    |
    | The quality of each format, from 1 to 100, when a spec or a preset gives
    | none. PNG and GIF are lossless.
    |
    */

    'quality' => [
        'jpeg' => 82,
        'webp' => 80,
        'avif' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Presets
    |--------------------------------------------------------------------------
    |
    | Named specs (docs/guides/presets.md): the options of a spec, plus
    | optional responsive "widths" and modern "formats":
    |
    |     'card' => ['width' => 800, 'ratio' => '16:9',
    |                'widths' => [400, 800, 1200], 'formats' => ['avif', 'webp']],
    |
    | Spec options: width, height, ratio ("16:9"), fit (cover, contain, inside,
    | fill), crop, rotate, flip, format (auto, jpeg, png, gif, webp, avif) and
    | quality. A preset can also be the name of a class that implements
    | Folklore\Image\Contracts\Preset.
    |
    | Each preset has a version, a short hash of its definition, in its URLs:
    | changing a preset gives it new URLs, so caches never serve the old
    | rendering for the new one. URLs of an earlier version are served while
    | their derivatives are stored, and never rendered again.
    |
    */

    'presets' => [],

    /*
    |--------------------------------------------------------------------------
    | URLs
    |--------------------------------------------------------------------------
    |
    | The host of image URLs, such as a CDN host (https://img.example.com);
    | null uses the application's URL. The prefix is the path before image
    | URLs, and the default prefix of Route::image().
    |
    */

    'url' => [
        'host' => env('IMAGE_URL'),
        'prefix' => 'img',
    ],

    /*
    |--------------------------------------------------------------------------
    | Signed URLs
    |--------------------------------------------------------------------------
    |
    | The first key signs, all of them verify, so a key rotates by putting the
    | new one first. Without keys, they are derived from APP_KEY and
    | APP_PREVIOUS_KEYS, so rotating the application key rotates them too.
    |
    | The default TTL is how long signed URLs last when signed() is given no
    | expiry, in seconds. Private sources always get URLs that expire.
    |
    */

    'signing' => [
        'keys' => array_values(array_filter(explode(',', (string) env('IMAGE_SIGNING_KEYS', '')))),
        'default_ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Strict mode
    |--------------------------------------------------------------------------
    |
    | Strict mode only serves presets at their current version (or an earlier
    | version while its derivative is stored), and signed URLs. Without it,
    | unsigned URLs can ask for any spec within the limits.
    |
    */

    'strict' => true,

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    |
    | Enforced on every image URL, signed or not: requests over a limit answer
    | 404. Null is no limit.
    |
    */

    'limits' => [
        'max_width' => 5000,
        'max_height' => 5000,
        'max_pixels' => 25_000_000,
        'max_operations' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Resolvers
    |--------------------------------------------------------------------------
    |
    | The resolvers of image references, by name, with their options. "disk"
    | serves the files of a Laravel disk, under a root, and caches their
    | version for "version_ttl" seconds. Null disables a resolver. Packages
    | add their own, such as the "media" resolver of laravel-mediatheque 2.
    |
    */

    'resolvers' => [
        'disk' => [
            'disk' => env('IMAGE_DISK', 'public'),
            'root' => '',
            'version_ttl' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Derivatives
    |--------------------------------------------------------------------------
    |
    | Where rendered images are kept: "disk" stores them on a Laravel disk,
    | under a directory; the name of a class implementing DerivativeStore
    | uses it instead. A derivative is rendered once: requests that miss it
    | at the same time wait for a lock on it, from a cache store that every
    | application server shares (null for the default store).
    |
    */

    'derivatives' => [
        'store' => 'disk',
        'disk' => env('IMAGE_DERIVATIVES_DISK', 'public'),
        'path' => 'derivatives',
        'lock' => [
            'store' => null,
            'seconds' => 60,
            'wait' => 30,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | "stream" streams the derivative; "redirect" redirects to its URL on its
    | disk, when the disk gives one and the source isn't private. The max-age
    | of image responses is in seconds; URLs that expire get at most their
    | remaining lifetime.
    |
    */

    'delivery' => [
        'serve' => 'stream',
        'max_age' => 31536000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upscaling
    |--------------------------------------------------------------------------
    |
    | Whether derivatives can be larger than their original. A URL can never
    | change it.
    |
    */

    'upscale' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory limit
    |--------------------------------------------------------------------------
    |
    | PHP's memory limit is raised to this value while rendering, never
    | lowered. Large photos need it with GD and Imagick; libvips streams them.
    |
    */

    'memory_limit' => '256M',

];
