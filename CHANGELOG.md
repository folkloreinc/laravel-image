# Changelog

All notable changes to `folklore/laravel-image` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

2.0 is in development on the `v2.x` branch, with Niche as the pilot project ([#59](https://github.com/folkloreinc/laravel-image/issues/59), [roadmap](docs/roadmap.md)). It is released in slices, tagged `v2.0.0-alpha.N`, then `beta.N` and `rc.N`. Until the release candidate, an alpha can break the previous one: each alpha lists its breaking changes since the previous one under **Breaking changes**.

Changes to 1.x after 1.1.0 are in the changelog of the [`v1.x`](https://github.com/folkloreinc/laravel-image/blob/v1.x/CHANGELOG.md) branch.

### Breaking changes

- `Image::routes()` no longer registers the 2.x route: register it with `Route::image()`. `Image::routes()` is kept for the 1.x route, which comes back with the compatibility layer in the beta; until then it throws a `BadMethodCallException`, so a 1.x project that updates never gets the 2.x route in place of its own ([decision 0011](docs/decisions/0011-image-routes-is-the-1x-route.md), #95).
- `DerivativeStore` has two new methods, `putPresetSpec()` and `presetSpec()`, which record the spec of each preset version rendered; `DerivativeStoreContractTests` tests them. Stores written for alpha.1 implement them ([decision 0012](docs/decisions/0012-earlier-preset-versions.md), #96).
- A spec's `focus` (`fp-…` in URLs) is relative to the upright original, like a source's focal point, and follows the spec's transform; in alpha.1 it applied to the image after the transform. Only specs with both a transform and a focal point render differently ([decision 0013](docs/decisions/0013-transform-focal-point-and-metadata.md), #88).
- The source version in URLs and derivative paths is `ImageSource::urlVersion()`: the resolver's version, followed by a hash of the source's transform and focal point when it has any (`v-8c1f02ab-3f9a1c`). Resolvers still give the version of the file (#88).
- A crop past the edges of the image is clamped to it, and a crop wholly outside leaves the whole image, instead of answering 404 (`InvalidImageSpec`). `RendererContractTests` checks it ([decision 0014](docs/decisions/0014-per-usage-transforms.md), #90).

### Added

- `PendingImage::transform()`, the edits of an image for one use, in Panneau 5's shape: without `base` (or with `base: "original"`) they replace the source's transform; with `base: "media"` they apply over it, composed into one crop, rotation and flip of the original, before the URL is made, so each use has its own URL. The focal point of a transform over the media's is relative to the media's version and mapped back to the original. Other keys, such as `baseTransform`, are ignored ([decision 0014](docs/decisions/0014-per-usage-transforms.md), #90).
- `Transform::fromArray()` reads Panneau 5's `MediaTransform`: `flipX` and `flipY`, and the `focalPoint`, which a `Transform` now carries. `ImageSource` takes the focal point of its transform as its own, and `ImageSpec` as its `focus` ([decision 0013](docs/decisions/0013-transform-focal-point-and-metadata.md), #88).
- The focal point is mapped from the upright original into the transformed image: it moves with the crop (and stays inside it), and turns with the rotation and the flips, on every driver (#88).
- With `laravel-mediatheque` 2 installed, `ClearMediaDerivatives` clears a media's derivatives, and those of its posters and thumbnails, when its visibility changes (`MediaVisibilityChanged`) or its original is replaced (`OriginalReplaced`) (#88).
- Decision 0013 records the transform shape, the metadata keys (`orientation` from 1 to 8, applied by this package; the dominant color is `palette[0]`) and the mediatheque's side of the contract (#88).

### Fixed

- Colors are converted to sRGB once the image is cropped and resized, as #92 did on 1.x, instead of on the whole original: with Imagick, a 6000×4000 Adobe RGB photo renders at 800 px in 0.5 s instead of 4.2 s, with the same colors on every driver (#93).
- Editing a source's transform or focal point gives new URLs. In alpha.1, the URL of an edited media didn't change, so CDNs and browsers kept serving the previous rendering as `immutable` (#88).
- Variants and limits take the source's transform into account: the dimensions of a cropped or rotated media's variants were those of its whole original (#88).
- URLs of an earlier version of a preset are served from their stored derivatives, as decisions 0004 and 0005 say, instead of answering 404 once the preset changes: a page cached with them keeps its images. Nothing is rendered for an earlier version, and derivatives rendered by alpha.1 aren't found this way ([decision 0012](docs/decisions/0012-earlier-preset-versions.md), #96).

## [2.0.0-alpha.1] - 2026-10-16

The first alpha of 2.0: presets and signed URLs for images on any Laravel disk, rendered once and served behind a CDN ([roadmap](docs/roadmap.md), alpha 1). Media ids resolve through the `media` resolver of `laravel-mediatheque` 2, which ships in its next alpha. Require it with `^2.0@alpha`.

### Breaking changes

- Requires PHP 8.3+ and Laravel 12+ ([decision 0001](docs/decisions/0001-supported-versions.md), #61).
- The 1.x code is removed, and Imagine with it: 2.x renders with Intervention Image 4 ([decision 0002](docs/decisions/0002-rendering-engine.md), #61, #62). The 1.x API comes back as a compatibility layer in the beta ([decision 0007](docs/decisions/0007-compatibility-layer.md)); 1.x URLs keep being decoded.
- No JavaScript package in 2.x: the server builds and signs every URL ([decision 0008](docs/decisions/0008-no-javascript-package.md), #62).

### Added

- `Image::for($reference)` returns an immutable `PendingImage` builder: `preset()`, `width()`, `height()`, `size()`, `fit()`, `ratio()`, `format()` and its shortcuts (`webp()`, `avif()`…), `quality()`, `signed(expires:)`, `host()`, then `url()`, `variant()`, `variants()` (a preset's responsive widths × formats, with their exact dimensions from the source's metadata) and `render()` (#70).
- `config/image.php`: versioned `presets`, `url.host` and `url.prefix`, `signing.keys` (derived from `APP_KEY` and `APP_PREVIOUS_KEYS` by default) and `signing.default_ttl`, `strict`, `limits`, `delivery`, `upscale`. A published config only needs the keys it changes (#70).
- Private sources only get signed URLs that expire; in strict mode, a free spec only gets a signed URL; limits are checked when a URL is built (#70).
- `ImageVariantResource`, the JSON shape of a variant in APIs: `{preset, url, width, height, mime}` (#70).
- Resolvers are registered from the config or with `Image::resolver()`; `Image::useHost()` changes the host of the URLs of a request (#70).
- `Route::image($prefix)`, opt-in, with the project's middleware (`Image::routes()` was an alias of it in this alpha only, #95). It serves the canonical v2 paths only; checks signatures and expiry (403); in strict mode, serves presets at their current version and widths, or signed URLs; enforces the limits; serves private sources only through signed URLs that expire; renders each derivative once under a lock (`image.derivatives.lock`); streams it, or redirects to the store's URL (`image.delivery.serve`) (#71).
- The route renders through the renderer's `RenderLock` (one lock per derivative, `image.derivatives.lock`). A request that waits too long for another request's render answers `503` with `Retry-After`. An end-to-end test covers the disk resolver, the Intervention renderer, the disk store and the route on every driver (#71).
- Caching headers: `Cache-Control: public, max-age=31536000, immutable` for versioned URLs, a `max-age` no longer than the remaining lifetime of URLs that expire, `private` for private sources, an `ETag` and `304` answers (#71).
- Guides: [getting started](docs/guides/getting-started.md), [presets](docs/guides/presets.md), [URLs and signatures](docs/guides/urls-and-signatures.md), [delivery behind a CDN](docs/guides/delivery-behind-a-cdn.md) with Cloudflare (#73).
- `php artisan image:doctor` checks the driver and its formats, FFI and libvips, the disks (`--write` writes, reads and deletes a file on the derivatives disk), the lock store, the signing key, the route (and whether it sets session cookies) and the URL host, and fails when one of them is missing (#72).
- `Image::fake()` works through the builder, and keeps derivatives in memory (`Folklore\Image\Testing\InMemoryDerivativeStore`) (#70).
- The public contracts of 2.0, with their tests: `ImageSpec`, presets and their versions, `UrlCodec` and the v2 URL syntax ([decision 0009](docs/decisions/0009-v2-url-syntax.md)), `Signer`, `SourceResolver` and `ImageSource`, `Renderer` and `Operation`, `DerivativeStore`, `ImageVariant`, `Image::fake()`, and the contract test traits for their implementations (#63).
- CI on PHP 8.3–8.5 × Laravel 12–13 × the GD, Imagick and libvips drivers, Larastan at the maximum level (#61).
- A release workflow: pre-releases are tagged from a green commit of `v2.x` and published on GitHub and Packagist (#64).
- `InterventionRenderer`, the default `Renderer`, on Intervention Image 4 with the `auto` driver (libvips, then Imagick, then GD; `image.renderer.driver`). It resizes with `fit` (cover around the focal point, contain, inside, fill) and `ratio`, applies the spec's or the source's transform, writes JPEG, PNG, GIF, WebP and AVIF with `image.quality` per format, never upscales by default, and raises `image.memory_limit` while rendering. It requires `intervention/image` 4.3.4 or later (#74).
- Every derivative is upright from its EXIF orientation, converted to sRGB from its embedded profile (Imagick and libvips) and stripped of its metadata, and HEIC sources are read with Imagick (libheif) or libvips, as in 1.1 (#75).
- The `disk` resolver, `DiskResolver`: images on any Laravel disk under an optional root (`image.resolvers.disk`), with path containment as in 1.1, a version from the file's modification time or its ETag on S3-compatible disks (cached for `version_ttl` seconds), and the upright dimensions, mime type and EXIF orientation read once per version and cached (#76).
- `DiskStore`, the default `DerivativeStore`: derivatives on any Laravel disk under `image.derivatives.path`, at the paths of decision 0009, with long-lived caching headers and the disk's URL for redirects when the disk is public; and `RenderLock`, which renders each derivative once behind an atomic lock on its key (`image.derivatives.lock`), and throws `RenderInProgress` when another request's render takes longer than the wait (#77).

## [1.1.0] - 2026-10-07

The first tagged release of `v1.x`: it stabilizes the package, without breaking existing image URLs or public APIs. Sites can require `folklore/laravel-image:^1.1` instead of `v1.x-dev`.

### Upgrade notes

What sites will notice, and how to keep the previous behaviour where there is a setting:

- **Output quality** follows `image.quality` (JPEG 82, WebP 80, AVIF 60), with and without the route cache. Uncached images were encoded at quality 100; set `image.quality` to 100 to keep that. A route's `quality` option still wins.
- **EXIF orientation:** phone photos are turned upright before any filter. Turn it off with `image.auto_orient`.
- **Metadata:** derivatives are stripped of EXIF and GPS data, and converted to sRGB with Imagick and Gmagick. Turn it off with `image.strip_metadata`.
- **New formats:** SVG and HEIC sources, which answered 404 or 415, are now served; HEIC as JPEG unless the URL asks for another format.
- **Restrictions** run in `log` mode: requests that break them are logged as warnings and still served. Check your logs before switching `image.restrictions.mode` to `enforce`.
- **URL options:** `upscale`, `auto_orient` and `memory_limit` in a URL are ignored. Set them on the route or in the config.
- **JS generator:** `format` is now the output format, as in PHP (a `format` containing `{…}` is still read as the URL template), and a source URL keeps its host.
- **Requirements:** PHP 8.2+ and Laravel 9+.

### Added

- GitHub Actions: tests on PHP 8.2–8.5 × Laravel 9–13 (`prefer-lowest` and `prefer-stable`), Pint, Larastan, and the JS suite and build.
- Laravel Pint, Larastan (with a baseline) and the Composer scripts `test`, `test-coverage`, `analyse` and `format`.
- URL fixtures shared by the PHP and JS suites, and feature tests that serve images through real routes.
- Tests for the `filesystem` source on an S3-compatible disk, run against an S3-compatible server (moto) in CI.
- Route restrictions (#11):
    - the documented route options `allow_size`, `allow_filters` and `disallow_filters` are now checked, and `headers` is added to the response;
    - global size limits (`image.restrictions`: `max_width`, `max_height`, `max_pixels`, `max_filters`), which each route can override;
    - in the default `log` mode, a request that breaks a restriction is logged as a warning and still served; in `enforce` mode it answers 404.
- HEIC sources (iPhone photos) are detected and served when the driver can read them (Imagick with libheif and its HEVC decoder): as JPEG by default, or in the requested format (`photo.heic.webp`). A driver that can't read a source, such as GD with a HEIC photo, answers 415 instead of 500 (#42).
- An `upscale` option (`image.upscale`, per route, or in `make()`): `false` never enlarges the source, even for crops, which then keep the requested ratio at the largest size the source allows. The default, `null`, keeps the v1 behaviour (#12).

### Changed

- **Requires PHP 8.2 and Laravel 9 or later** (`illuminate/support` ^9.0 to ^13.0). Projects on older versions that require `v1.x-dev` can no longer update the package: they keep the commit locked in their `composer.lock`.
- The test suite runs on Testbench 7–11 and PHPUnit 9.5–12.
- JS tooling: Jest is installed, ESLint uses a flat config, and Babel helpers are bundled.
- `composer.json`: `suggest` instead of the invalid `suggests` key, an accurate description, and a PSR-4 autoload instead of PSR-0 (#53).
- `guzzlehttp/guzzle` ^8 is allowed, next to ^6.3.1 and ^7.0.1.
- The README is rewritten for v1.x: requirements, auto-discovery, URL examples that are served as written (the old ones used `-image(...)`), filters, routes, sources, the JS generator, configuration and security notes (#51).
- Output quality is set per format with `image.quality` (JPEG 82, WebP 80, AVIF 60), and PNG is lossless at compression level 9. A route's `quality` option still overrides it for every format, and now also applies to cached files. Uncached responses were encoded at quality 100 (and uncompressed PNG), and cached files at the encoder defaults: both now produce the same file (#20).

### Fixed

- `image.memory_limit` is now a minimum: processing an image no longer lowers a higher memory limit, and keeps an unlimited one (#8).
- Image routes answer 415 for a source file that isn't a supported image and 404 for an unknown filter, instead of a 500 (#9).
- The `local` and `filesystem` sources reject paths that resolve outside their root, when reading (404) and when saving (`InvalidPathException`). The check is lexical, so symbolic links inside the root, such as `public/storage`, keep working (#10).
- Images are rotated and flipped according to their EXIF orientation before any filter, so resized phone photos are no longer sideways or upside down, and crops apply to the upright image. On by default: turn it off with `image.auto_orient`, a route's `auto_orient` option or the `auto_orient` option of `make()`; the URL can't. Requires the exif extension (#21).
- Images made by `make()`, and so every image served by a route, are stripped of their metadata (EXIF, GPS coordinates, comments). With the imagick and gmagick drivers, they keep the source's EXIF whenever no `Resize` ran, for example for a format conversion or a grayscale filter alone. Their colors are first converted to sRGB from the embedded profile, so wide-gamut photos (Display P3, Adobe RGB) no longer look washed out once the profile is gone; GD can't convert profiles. Turn it off with `image.strip_metadata` (#22).
- A `memory_limit` filter in an image URL is ignored: it could raise PHP's memory limit for that request above `image.memory_limit`. `make()` options and a route's `filters` can still set it (#26).
- Image URLs with a negative value, such as `rotate(-90)`, or a crop position, such as `crop(top_left)`, are parsed instead of answering 404. Both generators already produced them (#39).
- `filesystem` source (#40):
    - with `cache` on and no `cache_path`, the source no longer throws an `ArgumentCountError` once an image is cached, and caches the file contents instead of a stream the cache store can't serialize;
    - local disks are detected with Flysystem 3 and read in place again, instead of through the disk API and the source cache.
- SVG sources are detected (from their content, or their extension) and the default route pattern accepts `.svg`, so image routes serve and resize them. The SVG check looked at the image type instead of the path, so it never matched (#41).
- JS URL generator, aligned with the PHP one (#44):
    - the default URL template has `{format_extension}`, and the `format` option is the output format (`.jpg.webp`), as in PHP; a `format` containing placeholders is still read as the URL template;
    - it accepts the `pattern` and `host` options, and keeps the host of a source URL;
    - generator options (such as `placeholders_patterns`) are no longer added to the URL as filters.
- PHP URL generator: a `host` with a scheme, such as `https://cdn.example.com`, is used as is instead of giving `http://https://…` (#44).
- A published `config/image.php` that is old or partial gets the package defaults for the nested keys it doesn't set (in `url`, `routes`, `restrictions`, `quality` and `utils`). Without `url.placeholders_patterns`, for example, the application failed to boot (#43).
- `Utils::convertImage()` deletes its temporary files, including on failure, and only fetches `http` and `https` URLs. The new `image.utils.allowed_hosts` option restricts the hosts it fetches from (#13).

### Removed

- Travis CI, Coveralls, `phpcs.xml` and the Prettier PHP plugin.

[Unreleased]: https://github.com/folkloreinc/laravel-image/compare/v2.0.0-alpha.1...v2.x
[2.0.0-alpha.1]: https://github.com/folkloreinc/laravel-image/releases/tag/v2.0.0-alpha.1
[1.1.0]: https://github.com/folkloreinc/laravel-image/releases/tag/v1.1.0
