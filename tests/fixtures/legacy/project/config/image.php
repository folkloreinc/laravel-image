<?php

declare(strict_types=1);
use App\Filters\Sepia;
use Folklore\Image\Filters\Blur;
use Folklore\Image\Filters\Colorize;
use Folklore\Image\Filters\Gamma;
use Folklore\Image\Filters\Grayscale;
use Folklore\Image\Filters\Interlace;
use Folklore\Image\Filters\Negative;
use Folklore\Image\Filters\Resize;
use Folklore\Image\Filters\Rotate;

// The config/image.php of a 1.x project, made generic.

return [
    'filters' => [
        'blur' => Blur::class,
        'colorize' => Colorize::class,
        'gamma' => Gamma::class,
        'grayscale' => Grayscale::class,
        'interlace' => Interlace::class,
        'negative' => Negative::class,
        'rotate' => Rotate::class,
        'resize' => Resize::class,
        'sepia' => Sepia::class,
        'square' => ['width' => 500, 'height' => 500, 'crop' => true],
    ],
    'sizes' => [
        ['id' => 'original'],
        ['id' => 'thumbnail', 'width' => 200, 'height' => 200],
        ['id' => 'small', 'maxWidth' => 400, 'maxHeight' => 400],
        ['id' => 'large', 'maxWidth' => 1400, 'maxHeight' => 1400],
        ['id' => 'facebook', 'width' => 1200, 'height' => 630, 'crop' => true],
    ],
    'source' => 'public',
    'sources' => [
        'public' => [
            'driver' => 'local',
            'path' => public_path(),
            'cache' => false,
        ],
        'cloud' => [
            'driver' => 'filesystem',
            'disk' => 'public',
            'path' => null,
            'cache' => true,
            'cache_path' => storage_path('image/cache'),
        ],
    ],
    'url' => [
        'format' => '{dirname}/{filters}{basename}.{extension}',
        'filters_format' => 'filters/{filter}/',
        'filter_format' => '{key}_{value}',
        'filter_separator' => '/',
        'placeholders_patterns' => [
            'host' => '(.*?)?',
            'dirname' => '([A-Za-z0-9_\-\/]+?)?',
            'basename' => '([A-Za-z0-9_\-]+?)',
            'filename' => '([A-Za-z0-9_\-\.]+)',
            'extension' => '(jpeg|jpg|gif|png)',
        ],
    ],
    'routes' => [
        'map' => base_path('routes/images.php'),
        'domain' => null,
        'namespace' => null,
        'middleware' => [],
        'controller' => '\Folklore\Image\Http\ImageController@serve',
        'pattern_name' => 'image_pattern',
        'cache_middleware' => 'image.middleware.cache',
    ],
    'driver' => 'gd',
    'memory_limit' => '128M',
];
