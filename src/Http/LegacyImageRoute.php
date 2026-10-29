<?php

declare(strict_types=1);

namespace Folklore\Image\Http;

use Folklore\Image\Urls\V1Codec;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * The legacy route, which `Route::imageLegacy()` registers: it serves 1.x URLs, decoded by
 * V1Codec, with the 1.x options of the route (docs/decisions/0007-compatibility-layer.md):
 *
 *     Route::imageLegacy('{pattern}', [
 *         'as' => 'image',
 *         'source' => null,                 // a 1.x source of image.sources, or image.source
 *         'pattern' => [],                  // the 1.x URL template, over image.url
 *         'allow_size' => true,
 *         'allow_filters' => true,          // or the filters allowed
 *         'disallow_filters' => false,      // or the filters refused
 *         'restrictions' => [],             // over image.restrictions
 *         'upscale' => null,                // null: crops upscale, resizes don't, as 1.x
 *         'filters' => [],                  // applied to every image of the route
 *         'quality' => null,
 *         'expires' => 2678400,             // the max-age of responses, in seconds
 *         'headers' => [],
 *         'cache' => true,                  // writes each image at its path under cache_path
 *         'cache_path' => public_path(),    // so the web server serves it statically next time
 *     ]);
 *
 * `{pattern}` is replaced by the pattern of 1.x URLs; `domain`, `middleware`, `prefix` and
 * `namespace` are the route's. The deprecated 1.x `$router->image($path, $config)` registers it
 * too, with 1.x's defaults for the options it doesn't give (no cache, no expiry).
 *
 * @internal
 */
final class LegacyImageRoute
{
    /** The defaults of 1.x's route registrar, for the options a route doesn't give. */
    public const array REGISTRAR_DEFAULTS = [
        'source' => null,
        'pattern' => [],
        'allow_size' => true,
        'allow_filters' => true,
        'disallow_filters' => false,
        'restrictions' => [],
        'upscale' => null,
        'filters' => [],
        'quality' => null,
        'expires' => null,
        'headers' => [],
        'cache' => false,
        'cache_path' => null,
        'cache_mode' => 0755,
    ];

    /** The defaults of Route::imageLegacy(): those of the default 1.x route file. */
    public const array DEFAULTS = [
        'as' => 'image.legacy',
        'expires' => 3600 * 24 * 31,
        'cache' => true,
    ];

    /** The options that are the route's, not the image's. */
    private const array ROUTE_ATTRIBUTES = ['as', 'domain', 'middleware', 'name', 'namespace', 'prefix', 'uses', 'pattern_name'];

    /**
     * @param  string  $path  the path of the route, with `{pattern}`
     * @param  array<string, mixed>  $config  the options of the route
     */
    public static function register(Router $router, V1Codec $codec, string $path = '{pattern}', array $config = []): Route
    {
        $pattern = is_array($config['pattern'] ?? null) ? $config['pattern'] : [];
        $as = $config['as'] ?? $config['name'] ?? null;

        $parameter = $config['pattern_name'] ?? null;
        if (! is_string($parameter)) {
            $parameter = $pattern === [] || ! is_string($as) ? 'image_pattern' : 'image_pattern_'.preg_replace('/[^a-z0-9]+/i', '_', (string) preg_replace('/^image\./', '', $as));
        }

        $action = [
            'uses' => LegacyImageController::class,
            'middleware' => $config['middleware'] ?? [],
            'image' => array_diff_key($config, array_flip(self::ROUTE_ATTRIBUTES)),
            'image_parameter' => $parameter,
        ];
        if (is_string($as)) {
            $action['as'] = $as;
        }
        if (is_string($config['domain'] ?? null)) {
            $action['domain'] = $config['domain'];
        }

        $uri = (string) preg_replace('/\{\s*pattern\s*\}/i', '{'.$parameter.'}', $path);

        return $router->get($uri, $action)->where($parameter, $codec->withOptions($pattern)->pattern());
    }
}
