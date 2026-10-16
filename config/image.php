<?php

declare(strict_types=1);

/*
 * The config of folklore/laravel-image. A published copy only needs the keys it changes: the
 * package's defaults fill in the others, nested keys included. Presets and lists replace the
 * defaults instead of being merged with them.
 */
return [

    /*
     * Named specs (docs/guides/presets.md). A preset is the options of a spec, plus optional
     * responsive `widths` and modern `formats`:
     *
     *     'card' => ['width' => 800, 'ratio' => '16:9', 'widths' => [400, 800, 1200], 'formats' => ['avif', 'webp']],
     *
     * Spec options: width, height, ratio ('16:9'), fit (cover, contain, inside, fill), crop,
     * rotate, flip, format (auto, jpeg, png, gif, webp, avif) and quality. A preset can also be
     * the name of a class that implements Folklore\Image\Contracts\Preset.
     *
     * Each preset has a version, a short hash of its definition, in its URLs: changing a preset
     * gives it new URLs, so caches never serve the old rendering for the new one.
     */
    'presets' => [],

    'url' => [
        /*
         * The host of image URLs, such as a CDN host (https://img.example.com). Null uses the
         * application's URL.
         */
        'host' => env('IMAGE_URL'),

        /*
         * The path before image URLs, and the default prefix of Route::image().
         */
        'prefix' => 'img',
    ],

    'signing' => [
        /*
         * The keys of signed URLs: the first one signs, all of them verify, so a key can be
         * rotated by putting the new one first. Empty: keys are derived from APP_KEY and
         * APP_PREVIOUS_KEYS, so rotating the application key rotates them too.
         */
        'keys' => array_values(array_filter(explode(',', (string) env('IMAGE_SIGNING_KEYS', '')))),

        /*
         * How long signed URLs last when signed() is given no expiry, in seconds. Private
         * sources always get URLs that expire.
         */
        'default_ttl' => 3600,
    ],

    /*
     * Strict mode only serves presets at their current version, and signed URLs. Without it,
     * unsigned URLs can ask for any spec within the limits.
     */
    'strict' => true,

    /*
     * Enforced on every image URL, signed or not: requests over a limit answer 404.
     */
    'limits' => [
        'max_width' => 5000,
        'max_height' => 5000,
        'max_pixels' => 25_000_000,
        'max_operations' => 10,
    ],

    'delivery' => [
        /*
         * stream: the response streams the derivative. redirect: it redirects to the URL of
         * the derivative on its disk, when the disk gives one and the source isn't private.
         */
        'serve' => 'stream',

        /*
         * The max-age of image responses, in seconds. URLs that expire get at most their
         * remaining lifetime.
         */
        'max_age' => 31536000,
    ],

    /*
     * Whether derivatives can be larger than their original.
     */
    'upscale' => false,

];
