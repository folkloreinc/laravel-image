# Current state of 1.x

Audit of `folklore/laravel-image` `v1.x` (tag `v1.1.0`, October 2026), written to plan 2.0. Paths are relative to `src/Folklore/Image/` unless they start with `src/`, `js/` or `tests/`.

## Summary

The core idea of 1.x still holds: describe the manipulation in the URL, render the image once, and cache it so later requests skip Laravel. Phase 1 of [#3](https://github.com/folkloreinc/laravel-image/issues/3) (released as 1.1.0) made it reliable: working tests and CI on PHP 8.2–8.5 × Laravel 9–13, enforced restrictions (log-only by default), path containment, HEIC, EXIF orientation, sRGB and metadata stripping, quality per format, and URL fixtures shared by the PHP and JS generators.

What it lacks is what applications rebuild around it, or can't do at all:

- no named sizes, CDN host or variant dimensions: consumers reimplement them;
- URLs expose storage paths, can't be signed, and don't change when a size or an original changes;
- derivatives live on each server's `public/` directory;
- the route accepts any request unless restricted, and is registered by default;
- no responsive markup, no placeholders, no focal point, no libvips.

## Lines

- **`v1.x`:** PHP 8.2+, Laravel 9 to 13. Tagged `v1.1.0`. Most projects still require `v1.x-dev`.
- **`main` and `develop`:** stale.
- **npm `laravel-image` 1.x:** the JS URL generator.

## How it works

- **Entry point:** `Image` (behind the `Image` facade and the `image` container key) is the URL generator front, the filter registry and a factory of `ImageHandler`s, one per source.
- **URLs:** `UrlGenerator` fills a configurable template, `{dirname}/{basename}{filters}.{extension}{format_extension}`, with `-filters(300x300-crop-grayscale)`. The same template gives the route pattern and the parser. A format conversion adds a second extension (`photo-filters(800x_).jpg.webp`).
- **Filters:** `Filters/` holds `Resize`, `Rotate`, `Grayscale`, `Negative`, `Gamma`, `Blur`, `Colorize` and `Interlace`, applied in the order given. A project registers more with `Image::filter()`: an array of options (a named size), a closure, or a class implementing `Contracts\Filter` or `Contracts\FilterWithValue`. Closures and classes receive an Imagine image.
- **Sources:** `Sources/LocalSource` (a directory, `public_path()` by default) and `Sources/FilesystemSource` (a Laravel disk, with an optional local copy of remote originals). Both read originals and save derivatives.
- **Rendering:** `ImageHandler::make()` opens the original with Imagine (GD, Imagick or Gmagick), turns it upright, applies the filters, converts colors to sRGB and strips metadata.
- **Routes:** a catch-all route is registered from `routes/images.php` (published) or the package's own file, unless `routes.map` is `null`. `$router->image()` adds more. `RouteResolver` parses the URL, checks `RouteRestrictions`, and returns an `ImageResponse` with an `Expires` and `max-age` header.
- **Cache:** with `cache` on, `CacheMiddleware` writes the derivative under `cache_path` (`public_path()` by default) at the requested path, so the web server serves it statically next time. There is no lock.
- **Other:** the `image:create_url_cache` command and job, `Utils::convertImage()` (fetches a remote image and converts it), the `image()` and `image_url()` helpers.
- **JS:** `js/src/UrlGenerator.js` builds the same URLs from the same template.

## What projects use

About twenty applications require 1.x directly, and most of them also require `laravel-folklore`, which requires `^1.1`. What they use is small:

- `Image::url($path, $filters)` and `image_url()`, with named sizes (`['large']`) and options (`['format' => 'webp']`);
- the `-filters(...)` URL format, stored in content and in CDN caches, and pinned by `laravel-folklore`'s tests;
- the catch-all route and its public path cache;
- `Image::filter()` with arrays, to register named sizes.

Two conventions live outside the package:

- **`image.sizes`**, a list of `{ id, width | maxWidth, height | maxHeight }`, written by the project generator and registered as array filters (`width` and `height` together mean a crop);
- **`app.image_url`**, the CDN host, prepended by `laravel-folklore`.

`laravel-folklore`'s `Entities\Image` and `Entities\ImageSize` rebuild, on top of `Image::url()`: the host, named sizes, WebP conversion, the output dimensions (computed from the original's metadata, without upscaling), the format-to-mime map, and skipping SVG and `original`. Its `ImageSizeResource` returns `{ id, url, width, height, mime }`.

No known project imports the npm package. `laravel-mediatheque` 1.x makes its own thumbnails and doesn't use this package.

## Known limits

### Design

- **Several URLs per variant:** filters keep the order they were given, so the same rendering can be cached under several names.
- **Stale variants:** a named size keeps its URL when its definition changes, and so does a replaced original.
- **Storage paths in URLs**, and no private images.
- **Configurable URL template:** the route pattern, the parser and both generators must agree on it; most bugs fixed in 1.1 came from this.
- **Magic:** `Image` implements three contracts and the container key `image` is generic. Filters receive the engine's image type, which ties projects to Imagine.

### Delivery

- Derivatives are written to each server's `public/`, lost on deploys that replace it, and not shared between servers.
- Remote originals are copied to each server.
- Concurrent requests for a new variant all render it.
- `Expires` and `max-age` only: no `immutable`, no `ETag`, no `304`.

### Security

- The catch-all route is registered by default.
- Restrictions default to `log` mode, so any size and filter is rendered.
- No signed URLs.

### Features

- No presets with responsive widths and formats, no `srcset`, no `<picture>`, no variant dimensions.
- Crops have a position, but no focal point, ratio or density.
- No placeholders (BlurHash, ThumbHash, dominant color).
- No libvips: very large photos need a lot of memory with Imagick.
- Animated GIFs lose their animation.
- No events, no test fake, no command to check the installation.

## Tooling

- PHPUnit with Testbench, Pint, Larastan (with a baseline), GitHub Actions on the full matrix, including an S3-compatible disk and the HEIC decoder.
- Jest, ESLint and Prettier for the JS generator, built with Rollup and Babel.
- `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md`, `LICENSE.md`.
