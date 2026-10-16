# Upgrading from 1.x

> **Draft.** This guide is filled as 2.0 lands. The steps and the mapping below are the plan ([decisions/0007](decisions/0007-compatibility-layer.md)); the commands they mention don't exist yet.

2.0 keeps 1.x URLs working, forever, and a compatibility layer keeps the 1.x API working with deprecation notices until 3.0. A project can upgrade first, and move to the 2.x API afterwards.

## Before you start

- The project runs **PHP 8.3+ and Laravel 12+** ([decisions/0001](decisions/0001-supported-versions.md)). Upgrade Laravel first if needed; 1.x supports Laravel 9 to 13.
- The project requires a tagged 1.x release (`^1.1`), not `v1.x-dev`, and is on the latest one.
- If the project uses `laravel-folklore`, check which of its versions supports `laravel-image` 2.
- Install an image driver: libvips (`intervention/image-driver-vips`, with FFI enabled) or Imagick. GD works, with fewer formats.

## Steps

1. **Require the new version:** `composer require folklore/laravel-image:^2.0`.
2. **Check the project:** `php artisan image:upgrade --check`. It lists:
   - the config keys it will convert (`sizes`, `filters`, `sources`, `url`, `routes`, `restrictions`) and `app.image_url`;
   - custom filters written against Imagine (closures and classes), which must be rewritten;
   - calls to classes and container keys that 2.0 removes;
   - the image routes, and whether their cache writes under `public/`.
3. **Upgrade:** `php artisan image:upgrade`. It rewrites `config/image.php` in the 2.x shape with only the project's overrides, turns named sizes into presets, and replaces `routes/images.php` with the legacy route, so 1.x URLs and the files already cached under `public/` keep being served.
4. **Rewrite custom filters** as operations (see below).
5. **Run the Rector rules** when you are ready to move to the builder: `vendor/bin/rector process --config vendor/folklore/laravel-image/rector.php`.
6. **Run the project's tests**, and open pages that show images, including old content and a converted format (`.jpg.webp`).
7. **Fix the deprecations** at your own pace. They are logged on the `deprecations` channel.
8. **Later:** move templates to presets and `<x-image>`, put the image host behind a CDN with `Route::image()`, and remove the legacy route once no stored content links to 1.x URLs.

## What keeps working

| 1.x | In 2.x |
|---|---|
| `-filters(...)` URLs | Decoded by `V1Codec`, served by `Route::imageLegacy()`, for every future version |
| `Image::url($path, $width, $height, $filters)`, `image_url()` | Deprecated; return the same 1.x URLs |
| `Image::filter('name', [...])` | Deprecated; registers a preset |
| `image.sizes` | Read as presets when `image.presets` is not set |
| `app.image_url` | Read as the host when `image.url.host` is not set |
| `$router->image()`, `Image::routes()` | Deprecated; register a legacy route |
| `Image::make($path, $options)`, `image($path, $options)` | Deprecated; return an Intervention image instead of an Imagine image |
| The `Image` facade | Same name, new class (`Folklore\Image\Facades\Image`); the old alias still points to it |

## What changes

| 1.x | 2.0 |
|---|---|
| Imagine (GD, Imagick, Gmagick) | Intervention Image 4 (libvips, Imagick, GD); no Gmagick ([decisions/0002](decisions/0002-rendering-engine.md)) |
| Filters receive an Imagine image | Operations receive an Intervention image |
| Catch-all route registered by default | Routes are opt-in ([decisions/0005](decisions/0005-secure-by-default.md)) |
| Restrictions in `log` mode | Strict mode and enforced limits on 2.x routes; the legacy route keeps the project's 1.x settings |
| Derivatives under `public/` | Derivatives on any disk; `public/` through the legacy route ([decisions/0003](decisions/0003-delivery.md)) |
| `upscale` `null` (crops upscale) | `upscale` `false` on 2.x routes; the legacy route keeps the 1.x behaviour |
| `sources` (`local`, `filesystem`) | Resolvers (`disk`, `media`) ([decisions/0006](decisions/0006-sources-and-resolvers.md)) |
| Configurable URL template | Fixed v2 format; the template is kept for 1.x URLs only ([decisions/0004](decisions/0004-specs-and-urls.md)) |
| npm `laravel-image` 1.x generator | No JavaScript package: the server returns URLs and variants ([decisions/0008](decisions/0008-no-javascript-package.md)) |

## What is removed

- **Custom filters written against Imagine.** Rewrite them as operations:

  ```php
  // 1.x
  Image::filter('sepia', function ($image, $value) {
      $image->effects()->grayscale()->colorize($image->palette()->color('#704214'));

      return $image;
  });

  // 2.x
  final class Sepia implements Operation
  {
      public function apply(ImageInterface $image, mixed $value): ImageInterface
      {
          return $image->greyscale()->colorize(30, 15, 0);
      }
  }

  Image::operation('sepia', Sepia::class);
  ```

- `Utils::convertImage()`. Import remote images with `laravel-mediatheque` 2, which guards against SSRF.
- The `image:create_url_cache` command and job: use `image:warm`.
- The container keys `image.url`, `image.source`, `image.imagine`, `image.imagine_manager` and `image.router`, and the 1.x contracts in `Folklore\Image\Contracts`. Resolve `Folklore\Image\ImageManager` or the 2.x contracts instead. The generic `image` key is only bound by the compatibility layer.
- `guzzlehttp/guzzle`, `imagine/imagine` and `contao/imagine-svg` are no longer installed by this package.
