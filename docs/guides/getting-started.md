# Getting started

This guide takes a Laravel application from nothing to a preset URL served behind a CDN, with `folklore/laravel-image` 2.0 alpha 1. It needs PHP 8.3+ and Laravel 12+.

## Install

```bash
composer require folklore/laravel-image:^2.0@alpha
```

The package renders with [Intervention Image](https://image.intervention.io) 4 and the first driver it finds: libvips, then Imagick, then GD. libvips is the fastest and the lightest on memory; it needs the `intervention/image-driver-vips` package, libvips itself and PHP's FFI extension with `ffi.enable=true`:

```bash
composer require intervention/image-driver-vips
```

Publish the config. A published config only needs the keys it changes: the package's defaults fill in the others.

```bash
php artisan vendor:publish --tag=image-config
```

## Define a preset

A preset is a named rendering ([presets.md](presets.md)):

```php
// config/image.php
'presets' => [
    'card' => ['width' => 800, 'ratio' => '16:9', 'widths' => [400, 800, 1200], 'formats' => ['avif', 'webp']],
    'thumbnail' => ['width' => 200, 'height' => 200],
],
```

## Register the route

Nothing is served until the application registers the image route. Register it **outside the `web` middleware group**: the session middleware sets cookies, and CDNs don't cache responses that set cookies. In `bootstrap/app.php`:

```php
use Illuminate\Support\Facades\Route;

->withRouting(
    web: __DIR__.'/../routes/web.php',
    then: function () {
        Route::image(); // GET /img/{path}
    },
)
```

`Route::image()` returns the route, so it can take the application's own middleware, or a domain: `Route::image()->domain('img.example.com')`. It uses `image.url.prefix` (`img`) as its prefix, the prefix of the URLs the package builds.

## Build URLs

`Image::for()` takes a reference (a path on a disk, a media, anything a resolver supports) and returns an immutable builder:

```php
use Folklore\Image\Facades\Image;

Image::for('uploads/photo.jpg')->preset('card')->url();
// https://img.example.com/img/_/card.3f9a1c,v-8c1f02ab/disk/uploads/photo.jpg.jpg

Image::for('uploads/photo.jpg')->preset('card')->width(400)->webp()->url();
Image::for($media)->variants('card'); // each width in each format, with their dimensions
```

In Blade, the builder is a string:

```blade
<img src="{{ Image::for($post->cover)->preset('card') }}" alt="">
```

The host is `image.url.host`, from `IMAGE_URL`: a CDN host such as `https://img.example.com`. Without it, URLs use the application's URL. Delivery behind a CDN is in [delivery-behind-a-cdn.md](delivery-behind-a-cdn.md).

## Sources

The `disk` resolver serves files of a Laravel disk, and `laravel-mediatheque` 2 adds the `media` resolver, for media ids. Each resolver gives the source's version (so a replaced original gets new URLs) and its dimensions (so variants know their size without opening the file).

## Check the setup

```bash
php artisan image:doctor
```

It checks the driver and its formats, FFI and libvips, the disks, the lock store, the signing key, the route and the URL host, and fails when one of them is missing. Run it on each environment, and with `--write` to check that the derivatives disk is writable.

On several servers, derivatives must be on a shared disk (such as S3), and render locks in a shared cache store (Redis or the database), so a variant is rendered once.

## Test

`Image::fake()` keeps URLs and variants real, renders nothing and needs no image driver:

```php
Image::fake();

$this->get('/articles/1')->assertOk();

Image::assertUrlGenerated(preset: 'card');
Image::assertRendered(fn (ImageSpec $spec) => $spec->width === 800);
```

## Next

- [Presets](presets.md): definitions, versions, responsive widths and formats, variants in APIs.
- [URLs and signatures](urls-and-signatures.md): the URL syntax, signed and expiring URLs, private sources, strict mode and limits.
- [Delivery behind a CDN](delivery-behind-a-cdn.md): caching headers, Cloudflare, stream or redirect.
