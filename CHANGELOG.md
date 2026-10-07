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
- An `upscale` option (`image.upscale`, per route, or in `make()`): `false` never enlarges the source, even for crops, which then keep the requested ratio at the largest size the source allows. The default, `null`, keeps the v1 behaviour (#12).

### Changed

- **Requires PHP 8.2 and Laravel 9 or later** (`illuminate/support` ^9.0 to ^13.0). Projects on older versions that require `v1.x-dev` can no longer update the package: they keep the commit locked in their `composer.lock`.
- The test suite runs on Testbench 7–11 and PHPUnit 9.5–12.
- JS tooling: Jest is installed, ESLint uses a flat config, and Babel helpers are bundled.
- `composer.json`: `suggest` instead of the invalid `suggests` key, and an accurate description.
- Output quality is set per format with `image.quality` (JPEG 82, WebP 80, AVIF 60), and PNG is lossless at compression level 9. A route's `quality` option still overrides it for every format, and now also applies to cached files. Uncached responses were encoded at quality 100 (and uncompressed PNG), and cached files at the encoder defaults: both now produce the same file (#20).

### Fixed

- `image.memory_limit` is now a minimum: processing an image no longer lowers a higher memory limit, and keeps an unlimited one (#8).
- Image routes answer 415 for a source file that isn't a supported image and 404 for an unknown filter, instead of a 500 (#9).
- The `local` and `filesystem` sources reject paths that resolve outside their root, when reading (404) and when saving (`InvalidPathException`). The check is lexical, so symbolic links inside the root, such as `public/storage`, keep working (#10).
- `Utils::convertImage()` deletes its temporary files, including on failure, and only fetches `http` and `https` URLs. The new `image.utils.allowed_hosts` option restricts the hosts it fetches from (#13).

### Removed

- Travis CI, Coveralls, `phpcs.xml` and the Prettier PHP plugin.
