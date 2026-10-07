# Laravel Image

[![Latest Stable Version](https://poser.pugx.org/folklore/laravel-image/v/stable.svg)](https://packagist.org/packages/folklore/laravel-image)
[![Tests](https://github.com/folkloreinc/laravel-image/actions/workflows/run-tests.yml/badge.svg?branch=v1.x)](https://github.com/folkloreinc/laravel-image/actions/workflows/run-tests.yml)
[![Total Downloads](https://poser.pugx.org/folklore/laravel-image/downloads.svg)](https://packagist.org/packages/folklore/laravel-image)

URL-based image manipulation for Laravel, built on [Imagine](https://github.com/php-imagine/Imagine).

Describe the manipulation in the image URL, and the package generates the image on the first request. With the route cache on, the result is written next to the original, so your web server serves it as a static file from then on, without booting Laravel.

```
/uploads/photo.jpg                               the original
/uploads/photo-filters(300x300-crop).jpg         a 300×300 crop
/uploads/photo-filters(800x_-grayscale).jpg.webp 800px wide, black and white, as WebP
```

- Resize, crop (with a position), rotate, grayscale, negative, gamma, blur, colorize, interlace, and your own filters.
- Sources on the local disk or on any Laravel filesystem disk (S3 and compatible services).
- Output quality per format, EXIF orientation, sRGB conversion and metadata stripping, so phone photos come out upright, with the right colors and without GPS coordinates.
- JPEG, PNG, GIF, WebP, AVIF, SVG and, with Imagick, HEIC sources.
- Restrictions on what a URL can request, and a matching JS URL generator.

## Requirements

- PHP 8.2 to 8.5 and Laravel 9 to 13.
- An image driver: the [GD](https://www.php.net/manual/en/book.image.php), [Imagick](https://www.php.net/manual/en/book.imagick.php) or [Gmagick](https://www.php.net/manual/en/book.gmagick.php) extension. Imagick gives the best quality and the most formats.
- The [exif](https://www.php.net/manual/en/book.exif.php) extension, to detect formats and orientation.
- For HEIC photos: Imagick built with libheif and its HEVC decoder (on Ubuntu, `libheif-plugin-libde265`).

## Installation

```bash
composer require folklore/laravel-image
```

The service provider and the `Image` facade are registered automatically. Then publish the config and the route file:

```bash
php artisan vendor:publish --provider="Folklore\Image\ServiceProvider"
```

This creates `config/image.php` and `routes/images.php`. Both are optional: without them, the package uses its defaults, including a catch-all image route.

## Usage

### Image URLs

Generate URLs with the `Image` facade or the `image_url()` helper:

```php
Image::url('/uploads/photo.jpg', 300, 300, ['crop']);
// /uploads/photo-filters(300x300-crop).jpg

Image::url('/uploads/photo.jpg', 300, 300, ['crop' => 'top_left', 'grayscale']);
// /uploads/photo-filters(300x300-crop(top_left)-grayscale).jpg

image_url('/uploads/photo.jpg', 800, null, ['format' => 'webp']);
// /uploads/photo-filters(800x_).jpg.webp
```

```blade
<img src="{{ image_url('/uploads/photo.jpg', 300, 300, ['crop']) }}" alt="">
```

The size is `{width}x{height}`, with `_` for an automatic dimension. Without `crop`, the image fits inside the size; with `crop`, it fills it, cut from the center or from a position such as `top`, `bottom_right` or `left`. A `format` (`jpg`, `png`, `gif`, `webp`, `avif`) converts the output, and appears as a second extension.

### Making images in code

`Image::make()` takes the same options and returns an [Imagine image](https://imagine.readthedocs.io/):

```php
$image = Image::make('/uploads/photo.jpg', [
    'width' => 300,
    'height' => 300,
    'crop' => true,
    'grayscale' => true,
]);

Image::save($image, '/uploads/photo-thumbnail.jpg');
```

Use another source with `Image::source('cloud')->make(...)`, or open the original with `Image::open($path)` to use the Imagine API directly. The `image($path, $options)` helper is a shortcut for `Image::make()`.

### Built-in filters

| Filter | In a URL | Option |
| --- | --- | --- |
| Size | `300x200`, `300x_`, `_x200` | `width`, `height` |
| Crop | `crop`, `crop(top_left)` | `crop` (`true` or a position) |
| Rotate | `rotate(90)`, `rotate(-90)` | `rotate` (degrees) |
| Grayscale | `grayscale` | `grayscale` |
| Negative | `negative` | `negative` |
| Gamma | `gamma(1.5)` | `gamma` |
| Blur | `blur(5)` | `blur` (sigma) |
| Colorize | `colorize(ff0000)` | `colorize` |
| Interlace | `interlace` | `interlace` |

### Custom filters

Declare filters in a service provider, as an array of other filters, a closure, or a class:

```php
// A preset
Image::filter('thumbnail', ['width' => 300, 'height' => 300, 'crop' => true]);

// A closure, which gets the Imagine image and the value from the URL
Image::filter('sepia', function ($image, $value) {
    $image->effects()->grayscale()->colorize($image->palette()->color('#704214'));

    return $image;
});

// A class implementing Folklore\Image\Contracts\Filter or FilterWithValue
Image::filter('watermark', \App\Image\Watermark::class);
```

`Image::url('/uploads/photo.jpg', ['thumbnail'])` then gives `/uploads/photo-filters(thumbnail).jpg`. You can also list them in the `filters` section of `config/image.php`.

### Routes

The default image route matches any URL in the format above, reads the original from the default source and, with its cache on, writes the result under `public/` at the requested path. The published `routes/images.php` holds that route and documents every option; change it, or add routes with the `image` router macro:

```php
$router->image('thumbnails/{pattern}', [
    'as' => 'image.thumbnails',
    'source' => 'cloud',

    // Only allow these filters in the URL, and no free size
    'allow_size' => false,
    'allow_filters' => ['thumbnail', 'grayscale'],

    // Applied to every image of this route
    'filters' => ['interlace' => true],

    // Write the result next to the requested path, to serve it statically next time
    'cache' => true,
    'cache_path' => public_path(),

    'quality' => 80,
    'expires' => 3600 * 24 * 31,
]);
```

Pass `['route' => 'image.thumbnails']` to `Image::url()` to generate URLs for a route.

### Sources

A source is where originals are read and derivatives saved, set in the `sources` section of the config:

- `local`: a directory on the server, `public_path()` by default.
- `filesystem`: a Laravel filesystem disk. For remote disks, `cache` keeps a local copy of the originals in `cache_path` (or in the cache store when `cache_path` is null).

Paths that resolve outside a source root answer 404.

### JavaScript URL generator

The npm package [`laravel-image`](https://www.npmjs.com/package/laravel-image) generates the same URLs in the browser:

```js
import { UrlGenerator } from 'laravel-image';

const urlGenerator = new UrlGenerator();
urlGenerator.make('/uploads/photo.jpg', 300, 300, { crop: true });
// /uploads/photo-filters(300x300-crop).jpg
```

It accepts the same options as `Image::url()`, including `format`, `pattern` and `host`. If you changed the URL format, pass your `image.url` config to the constructor.

## Configuration

`config/image.php` documents every key. The main ones:

| Key | Default | Purpose |
| --- | --- | --- |
| `driver` | `gd` | `gd`, `imagick` or `gmagick` |
| `source`, `sources` | `public` | where originals are read and saved |
| `filters` | built-in filters | the filters available in URLs and `make()` |
| `url` | `-filters(...)` format | the URL format, shared by the generators and the routes |
| `routes` | catch-all route | where `routes/images.php` lives, and the default route settings |
| `restrictions` | `log` mode | size and filter limits on URLs (see below) |
| `quality` | JPEG 82, WebP 80, AVIF 60 | output quality per format; PNG is lossless |
| `upscale` | `null` | whether sizes larger than the original enlarge it |
| `auto_orient` | `true` | turn photos upright from their EXIF orientation |
| `strip_metadata` | `true` | remove EXIF and GPS data, and convert colors to sRGB |
| `memory_limit` | `128M` | minimum memory limit while processing an image |

A published config only needs the keys you change: missing keys fall back to the package defaults.

## Security and production

- **Restrictions.** URLs can request any size or filter unless you limit them. `restrictions` sets maximum sizes, pixels and filters; in the default `log` mode, requests that break them are logged and still served, so you can check your traffic before switching to `enforce` (404). Routes can also allow only some filters (`allow_filters`) or no free size (`allow_size`).
- **Options a URL can't set.** `upscale`, `auto_orient` and `memory_limit` are decided by the config or the route, never by the URL.
- **Metadata.** Derivatives are stripped of EXIF and GPS data by default (`strip_metadata`).
- **Static files.** With the route cache, generated images are written under `cache_path` and served by the web server. Clear them by deleting the files.
- **Remote images.** `Utils::convertImage()` only fetches `http` and `https` URLs; restrict the hosts with `image.utils.allowed_hosts`.

## Upgrading within v1.x

`v1.x` keeps existing URLs and public APIs working. Some output changes in v1.1 (quality per format, EXIF orientation, metadata stripping, HEIC served as JPEG) can be turned off in the config; see the [changelog](CHANGELOG.md).

## Contributing

```bash
composer test      # PHPUnit
composer analyse   # Larastan
composer format    # Laravel Pint
npm test           # Jest
npm run lint       # ESLint and Prettier
```

See [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow, and [SECURITY.md](SECURITY.md) to report a vulnerability privately. The plan for the next versions lives in [#3](https://github.com/folkloreinc/laravel-image/issues/3).

## License

MIT. See [LICENSE.md](LICENSE.md).
