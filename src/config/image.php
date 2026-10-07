<?php

use Folklore\Image\Filters\Blur;
use Folklore\Image\Filters\Colorize;
use Folklore\Image\Filters\Gamma;
use Folklore\Image\Filters\Grayscale;
use Folklore\Image\Filters\Interlace;
use Folklore\Image\Filters\Negative;
use Folklore\Image\Filters\Resize;
use Folklore\Image\Filters\Rotate;

return [

    /*
    |--------------------------------------------------------------------------
    | Image Filters
    |--------------------------------------------------------------------------
    |
    | The list of filters you can use when making an image or generating an url.
    | There is some built-in filters, and you can add or replace any. It is also
    | possible to declare a filter with an array or a closure instead of a Filter
    | Class.
    |
    */
    'filters' => [
        'blur' => Blur::class,
        'colorize' => Colorize::class,
        'gamma' => Gamma::class,
        'grayscale' => Grayscale::class,
        'interlace' => Interlace::class,
        'negative' => Negative::class,
        'rotate' => Rotate::class,
        'resize' => Resize::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Source
    |--------------------------------------------------------------------------
    |
    | This option define the default source to be used by the Image facade. The
    | source determine where the image files are read and saved.
    |
    */
    'source' => 'public',

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | The list of sources where you store images.
    |
    | Supported driver: "local", "filesystem"
    |
    */
    'sources' => [

        'public' => [
            // The local driver use a local path on the machine.
            'driver' => 'local',

            // The path where the images are stored.
            'path' => public_path(),
        ],

        'cloud' => [
            // The filesystem driver lets you use the filesystem from laravel.
            'driver' => 'filesystem',

            // The filesystem disk where the images are stored.
            'disk' => 'public',

            // The path on the disk where the images are stored. If set to null,
            // it will start from the root.
            'path' => null,

            // Cache the file on local machine. It can be useful for remote files.
            'cache' => true,

            // The path where you want to put cached files
            'cache_path' => storage_path('image/cache'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | URL Generator
    |--------------------------------------------------------------------------
    |
    | The URL Generator configuration is used when generating an image url
    | and by the router to generate a pattern for catching image requests.
    | These are the defaults values and you can overide it in each routes or
    | when generating an url using the `pattern` parameter.
    |
    */
    'url' => [
        // The format of the url that will be generated. The `{filters}` placeholder
        // will be replaced by the filters according to the `filters_format`.
        'format' => '{dirname}/{basename}{filters}.{extension}{format_extension}',

        // The format of the filters that will replace `{filters}` in the
        // url `format` above. The `{filter}` placeholder will be replaced by
        // each filter according to the `filter_format` and joined
        // by the `filter_separator`.
        'filters_format' => '-filters({filter})',

        // The format of a filter.
        'filter_format' => '{key}({value})',

        // The separator for each filter
        'filter_separator' => '-',

        // This is the regex that will replace any placeholders in the option 'format'.
        // They are used when the route pattern is generated and added to the
        // Laravel Router to match image request.
        'placeholders_patterns' => [
            'host' => '(.*?)?',
            'dirname' => '(.*?)?',
            'basename' => '([^\/\.]+?)',
            'filename' => '([^\/]+)',
            'extension' => '(jpeg|jpg|gif|png|webp|avif|bmp|heic)',
            'format_extension' => '(\.(jpeg|jpg|gif|png|webp|avif))?',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Default configuration for image routes. See routes/image.php
    |
    */
    'routes' => [
        // Path to the routes file that will be automatically loaded. Set to null
        // to prevent auto-loading of routes.
        'map' => base_path('routes/images.php'),

        // Default domain for routes
        'domain' => null,

        // Default namespace for controller
        'namespace' => null,

        // Default middlewares for routes
        'middleware' => [],

        // The controller serving the images
        'controller' => '\Folklore\Image\Http\ImageController@serve',

        // The name of the pattern that will be added to the Laravel Router.
        'pattern_name' => 'image_pattern',

        // The middleware used when a route as `cache` enabled
        'cache_middleware' => 'image.middleware.cache',
    ],

    /*
    |--------------------------------------------------------------------------
    | Route restrictions
    |--------------------------------------------------------------------------
    |
    | Limits on what an image URL can request, checked on every image route
    | together with the route options `allow_size`, `allow_filters` and
    | `disallow_filters`. A route can override any of these settings with its
    | own `restrictions` array.
    |
    | Modes:
    | - "log": a request that breaks a restriction is logged as a warning and
    |   still served. Use it to find out what your sites request before
    |   enforcing anything.
    | - "enforce": such a request answers 404.
    |
    | Width and height count in pixels, `max_pixels` is width × height when both
    | are requested, and `max_filters` doesn't count the size (width, height,
    | crop). Set a limit to null to disable it.
    |
    */
    'restrictions' => [
        'mode' => 'log',
        'max_width' => 5000,
        'max_height' => 5000,
        'max_pixels' => 25000000,
        'max_filters' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Upscaling
    |--------------------------------------------------------------------------
    |
    | Whether a requested size larger than the source image enlarges it.
    |
    | - null: v1 behaviour. Crops upscale to the exact requested size; plain
    |   resizes don't.
    | - true: always upscale.
    | - false: never upscale. A crop keeps the requested ratio at the largest
    |   size the source allows.
    |
    | A route can override it with its own `upscale` option. The URL can't.
    |
    */
    'upscale' => null,

    /*
    |--------------------------------------------------------------------------
    | Orientation
    |--------------------------------------------------------------------------
    |
    | Whether images are rotated and flipped according to their EXIF
    | orientation before any filter, as phone cameras store most photos
    | sideways. Requires the exif extension.
    |
    | A route can override it with its own `auto_orient` option. The URL can't.
    |
    */
    'auto_orient' => true,

    /*
    |--------------------------------------------------------------------------
    | Metadata and color profiles
    |--------------------------------------------------------------------------
    |
    | Whether images made by `make()`, and so every image served by a route,
    | are stripped of their metadata (EXIF, GPS coordinates, comments). With
    | the imagick and gmagick drivers, colors are first converted to sRGB from
    | the embedded profile, such as Display P3 or Adobe RGB. GD can't convert
    | profiles: it always drops them, with the metadata.
    |
    | Turn it off only if you need to keep the metadata of the source.
    |
    */
    'strip_metadata' => true,

    /*
    |--------------------------------------------------------------------------
    | Image Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default image "driver" used by Imagine library
    | to manipulate images.
    |
    | Supported: "gd", "imagick", "gmagick"
    |
    */
    'driver' => 'gd',

    /*
    |--------------------------------------------------------------------------
    | Output quality
    |--------------------------------------------------------------------------
    |
    | The quality used to encode each format, from 0 to 100, with and without
    | the route cache. A route's `quality` option overrides it for every format.
    | PNG is always lossless and compressed as much as possible.
    |
    */
    'quality' => [
        'jpeg' => 82,
        'webp' => 80,
        'avif' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Memory limit
    |--------------------------------------------------------------------------
    |
    | When manipulating an image, the memory limit is raised to at least this
    | value. A higher limit (or -1, unlimited) is kept as is.
    |
    */
    'memory_limit' => '128M',

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
    |
    | Remote images fetched by `Folklore\Image\Utils` (for example by
    | `Utils::convertImage()`). Only http and https URLs are fetched. Set
    | `allowed_hosts` to a list of hosts to fetch only from those hosts; with a
    | list, redirects aren't followed.
    |
    */
    'utils' => [
        'allowed_hosts' => null,
    ],

];
