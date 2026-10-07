# Changelog

All notable changes to `folklore/laravel-image` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
- `composer.json`: `suggest` instead of the invalid `suggests` key, and an accurate description.
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
