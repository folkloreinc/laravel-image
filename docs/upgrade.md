# Upgrading from 1.x

> **Beta.** The compatibility layer, `Route::imageLegacy()` and `image:upgrade` are in 2.0 ([#107](https://github.com/folkloreinc/laravel-image/issues/107)). The Rector rules, `Image::make()` and the 1.x filters `blur`, `gamma`, `colorize` and `interlace` are not there yet ([roadmap](roadmap.md)).

2.0 keeps 1.x URLs working, forever, and a compatibility layer keeps the 1.x API working with deprecation notices until 3.0. A project can upgrade first, and move to the 2.x API afterwards.

## Before you start

- The project runs **PHP 8.3+ and Laravel 12+** ([decisions/0001](decisions/0001-supported-versions.md)). Upgrade Laravel first if needed; 1.x supports Laravel 9 to 13.
- The project requires a tagged 1.x release (`^1.1`), not `v1.x-dev`, and is on the latest one.
- If the project uses `laravel-folklore`, check which of its versions supports `laravel-image` 2.
- Install an image driver: libvips (`intervention/image-driver-vips`, with FFI enabled) or Imagick. GD works, with fewer formats.

## Steps

1. **Require the new version:** `composer require folklore/laravel-image:^2.0`. The project keeps working with its 1.x `config/image.php` and `routes/images.php`: its 1.x URLs, `Image::url()`, `image()->url()` and the `image` key of the container are the compatibility layer's.
2. **Check the project:** `php artisan image:upgrade --check`. It changes nothing, and lists:
   - the config keys it will convert (`sizes`, `filters`, `source`, `sources`, `url`, `routes`, `restrictions`, `driver`…) and `app.image_url`;
   - every key it drops, with its value: keys 2.x doesn't read, and values that are 1.x's defaults;
   - what would be lost: keys it doesn't know, and values that come from `env()` or other code, which it would write as they are on this machine;
   - custom filters written against Imagine (closures and classes), in the config and in the code, which must be rewritten;
   - the lines of `app/`, `config/`, `routes/`, `resources/`, `database/` and `bootstrap/` that use the classes and container keys 2.0 removes;
   - the image routes, whether their cache writes under `public/`, and a config without a `routes` key, which 1.x gave its default route and 2.x gives none until it is upgraded.
3. **Upgrade:** `php artisan image:upgrade`. It rewrites `config/image.php` in the 2.x shape, with only the project's overrides: named sizes and array filters become presets, the 1.x URL template, sources, restrictions and `upscale` are kept for 1.x URLs, and `image.sizes` is kept while the code reads it. It replaces `routes/images.php` with `Route::imageLegacy()` when it only calls `$router->image()`, and leaves it as it is otherwise; a config without a `routes` key gets `routes/images.php` with the default route, as 1.x had. So 1.x URLs and the files already written under `public/` keep being served.
   - It refuses when something would be lost (see `--check`): move it first, or run it with `--force` to write the values it has on this machine.
   - It keeps each file it rewrites as `config/image.php.1x` and `routes/images.php.1x`, and never changes stored content or the images already written. Review the files with `git diff`.
4. **Rewrite custom filters** as operations (see below).
5. **Run the project's tests**, and open pages that show images, including old content and a converted format (`.jpg.webp`).
6. **Fix the deprecations** at your own pace. They are logged on Laravel's `deprecations` channel (`LOG_DEPRECATIONS_CHANNEL`), once per call site.
7. **Later:** move templates to presets and `<x-image>`, put the image host behind a CDN with `Route::image()`, and remove the legacy route once no stored content links to 1.x URLs. The Rector rules will help move code to the builder (planned).

## The legacy route

`Route::imageLegacy()` serves 1.x URLs as 1.x did. `image:upgrade` writes it in `routes/images.php`, which `image.routes.map` keeps loading at boot; the 1.x `$router->image($path, $config)` and `Image::routes()` register it too, deprecated:

```php
Route::imageLegacy('{pattern}', [
    'as' => 'image',
    'source' => null,              // a source of image.sources, or image.source
    'pattern' => [],               // the 1.x URL template, over image.url
    'allow_size' => true,
    'allow_filters' => true,       // or the list of filters allowed
    'disallow_filters' => false,   // or the list of filters refused
    'restrictions' => [],          // over image.restrictions: 'log' (1.x's default) or 'enforce'
    'upscale' => null,             // null: image.upscale; when it is null too, crops upscale and resizes don't, as 1.x
    'filters' => [],               // applied to every image of the route
    'quality' => null,
    'expires' => 2678400,          // the max-age of responses
    'headers' => [],
    'cache' => true,               // writes each image at its path under cache_path,
    'cache_path' => public_path(), // where the web server serves it next time
]);
```

- `V1Codec` decodes the URLs with the project's 1.x template: `-filters(...)` by default, or its own, such as `{dirname}/{filters}{basename}.{extension}` with `filters/{filter}/`. Every spelling 1.x accepted keeps working, and each is a URL of its own.
- A named filter (`-filters(large)`) is the filter registered with `Image::filter()`, or else the preset of that name, so 1.x URLs keep working once named sizes are presets.
- Sizes: `300x200` fits the image inside the box, `300x200-crop` crops it to the box, around a crop position (`crop(top_left)`) if there is one. A width or a height alone resizes proportionally; with `crop`, 1.x cropped the other dimension to the original's, 2.x doesn't.
- `rotate` takes multiples of 90 degrees. `grayscale` and `negative` are operations; a URL with another filter, or a rotation 2.x can't do, answers 404, as 1.x did for a filter it didn't have.
- Sources: `local` sources read a path, such as `public_path()`; `filesystem` sources read a Laravel disk, under a path.
- Images are rendered on demand, without the 2.x derivatives store, and written under `cache_path` when `cache` is true. A route without `cache` renders each request, as in 1.x.
- The 2.x limits and strict mode don't apply: the route keeps the project's 1.x restrictions. A preset named in a URL is a size for `allow_size` and `allow_filters`, as the 1.x filter it was made from was.

## The 1.x config

These 1.x keys of `config/image.php` are read by the compatibility layer only, where 1.x had them, with 1.x's defaults:

| Key | Read as |
|---|---|
| `url.format`, `url.filters_format`, `url.filter_format`, `url.filter_separator`, `url.placeholders_patterns` | The template of 1.x URLs, for `Image::url()` and the legacy route. They sit next to the 2.x `url.host` and `url.prefix` |
| `source`, `sources` | The sources of the legacy route |
| `restrictions` | The restrictions of the legacy route |
| `filters` | Arrays of options are named filters; the 1.x filter classes are ignored |
| `sizes` | Presets, when `image.presets` is not set, as the boot code of 1.x projects registered them: `width` (or `maxWidth`) and `height` (or `maxHeight`), cropped only when both `width` and `height` are set. The `crop` key of a size is ignored, as that code ignored it |
| `routes` | `routes.map` is loaded at boot, in a group of `domain`, `prefix`, `as` and `middleware`. Without a `routes` key, 2.x loads nothing: 1.x's default catch-all route comes back with `image:upgrade` |
| `upscale` | The upscaling of legacy routes that don't set theirs: `null` (the default) is 1.x's, where crops upscale and resizes don't. On 2.x routes, `null` is `false` |
| `bind_image` (2.x) | Whether `app('image')` is this package's manager, as in 1.x. It replaces Laravel 13's own `image` service. Null binds it for a 1.x config; `image:upgrade` writes it |

## What keeps working

| 1.x | In 2.x |
|---|---|
| `-filters(...)` URLs, and URLs of a project's own template | Decoded by `V1Codec`, served by `Route::imageLegacy()`, for every future version |
| `Image::url($path, $width, $height, $filters)`, `image_url()`, `image()->url()`, `app('image')->url()` | Deprecated; return the same 1.x URLs, without a host unless one is given |
| `Image::filter('name', [...])` | Deprecated; registers a named filter for 1.x URLs, and a preset |
| `image.sizes` | Read as presets when `image.presets` is not set |
| `app.image_url` | Read as the host of 2.x URLs when `image.url.host` is not set. `Image::url()` returns 1.x URLs without it, as 1.x did |
| `$router->image($path, $config)`, `Image::routes()`, `image.routes.map` | Register the legacy route; the first two are deprecated |
| `Image::make($path, $options)`, `image($path, $options)` | Not yet: they throw, and will return an Intervention image instead of an Imagine image (planned) |
| The `Image` facade | Same name, new class (`Folklore\Image\Facades\Image`); `Folklore\Image\Facade` and `Folklore\Image\ServiceProvider`, in `config/app.php`, are deprecated aliases |

## What changes

| 1.x | 2.0 |
|---|---|
| Imagine (GD, Imagick, Gmagick) | Intervention Image 4 (libvips, Imagick, GD); no Gmagick ([decisions/0002](decisions/0002-rendering-engine.md)) |
| Filters receive an Imagine image | Operations receive an Intervention image |
| Catch-all route registered by default | Routes are opt-in ([decisions/0005](decisions/0005-secure-by-default.md)) |
| Restrictions in `log` mode | Strict mode and enforced limits on 2.x routes; the legacy route keeps the project's 1.x restrictions |
| Derivatives under `public/` | Derivatives on any disk; `public/` through the legacy route ([decisions/0003](decisions/0003-delivery.md)) |
| `upscale` `null` (crops upscale) | `upscale` `false` on 2.x routes; the legacy route keeps the 1.x behaviour |
| `sources` (`local`, `filesystem`) | Resolvers (`disk`, `media`) ([decisions/0006](decisions/0006-sources-and-resolvers.md)) |
| Configurable URL template | Fixed v2 format; the template is kept for 1.x URLs only ([decisions/0004](decisions/0004-specs-and-urls.md)) |
| npm `laravel-image` 1.x generator | No JavaScript package: the server returns URLs and variants ([decisions/0008](decisions/0008-no-javascript-package.md)) |

## What is removed

- **Custom filters written against Imagine.** `Image::filter()` ignores them, with a deprecation, and the URLs that use them answer 404. Rewrite them as operations:

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
