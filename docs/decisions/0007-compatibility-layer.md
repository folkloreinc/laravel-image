# 0007: A compatibility layer for 1.x until 3.0

- Status: accepted
- Date: 2026-10-16

## Context

About twenty applications require 1.x, most of them through `laravel-folklore`. What they actually use is small:

- `Image::url($path, $filters)` and the `image_url()` helper, with named sizes (`['large']`) and options (`['format' => 'webp']`);
- the `-filters(...)` URL format, stored in content and in CDN caches;
- an `image.sizes` config key, registered as array filters with `Image::filter()`, and an `app.image_url` key for the CDN host. Both are conventions of the project generator and `laravel-folklore`, not keys of this package;
- the catch-all route, with its cache under `public/`.

Few of them write custom filters. Upgrading should be a dependency bump and one command for a typical project, with time afterwards to move to the 2.x API.

## Decision

- 2.x keeps the 1.x API working, with **deprecation notices**, until **3.0**:
  - `Image::url()`, `image_url()`, `Image::make()` and `image()`, mapped to the builder;
  - `Image::filter()` with an array of options, registered as a preset;
  - `image.sizes`, read as presets, and `app.image_url`, read as the host, when the 2.x keys are not set;
  - the `$router->image()` macro and `Image::routes()`.
- **URLs keep their 1.x shape** in compatibility mode: `Image::url()` returns a `-filters(...)` URL, so existing templates and tests produce the same URLs. The builder returns v2 URLs.
- A **legacy route**, `Route::imageLegacy()`, serves 1.x URLs through `V1Codec`, with the 1.x restrictions and the public path cache, so a project's web server keeps serving cached files statically. `image:upgrade` registers it for projects that had the 1.x route.
- **`php artisan image:upgrade`**:
  - `--check` lists what the project uses: config keys, custom filters, routes, calls to removed classes;
  - by default, it rewrites `config/image.php` to the 2.x shape, keeping only the project's overrides, and replaces `routes/images.php` with the legacy route;
  - it never changes stored content or cached files.
- **Rector rules** (`vendor/folklore/laravel-image/rector.php`) move code from the 1.x API to the builder.
- Deprecations are logged on the `deprecations` channel, once per call site.
- **What the layer can't keep:** custom filters written against Imagine ([0002](0002-rendering-engine.md)), `Utils::convertImage()` (importing remote images belongs to the media library), the internal container keys (`image.url`, `image.source`, `image.imagine`…), and the 1.x contracts.

## Consequences

- A typical project upgrades with `composer require folklore/laravel-image:^2.0` and `php artisan image:upgrade`. Its URLs, templates and `laravel-folklore` entities keep working.
- The layer has its own tests, built from the 1.x URL fixtures and the 1.x README examples.
- `laravel-folklore` 2 moves to the builder and `ImageVariant`, so its projects lose most of their deprecations by upgrading it.
- 3.0 removes the layer, but not `V1Codec`: 1.x URLs keep being decoded.
