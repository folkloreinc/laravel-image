<?php

declare(strict_types=1);

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
    | Memory limit
    |--------------------------------------------------------------------------
    |
    | PHP's memory limit is raised to this value while rendering, never
    | lowered. Large photos need it with GD and Imagick; libvips streams them.
    |
    */

    'memory_limit' => '256M',

];
