# Changelog

All notable changes to `folklore/laravel-image` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- GitHub Actions: tests on PHP 8.2–8.5 × Laravel 9–13 (`prefer-lowest` and `prefer-stable`), Pint, Larastan, and the JS suite and build.
- Laravel Pint, Larastan (with a baseline) and the Composer scripts `test`, `test-coverage`, `analyse` and `format`.
- URL fixtures shared by the PHP and JS suites, and feature tests that serve images through real routes.
- Tests for the `filesystem` source on an S3-compatible disk, run against an S3-compatible server (moto) in CI.

### Changed

- **Requires PHP 8.2 and Laravel 9 or later** (`illuminate/support` ^9.0 to ^13.0). Projects on older versions that require `v1.x-dev` can no longer update the package: they keep the commit locked in their `composer.lock`.
- The test suite runs on Testbench 7–11 and PHPUnit 9.5–12.
- JS tooling: Jest is installed, ESLint uses a flat config, and Babel helpers are bundled.
- `composer.json`: `suggest` instead of the invalid `suggests` key, and an accurate description.

### Fixed

- `image.memory_limit` is now a minimum: processing an image no longer lowers a higher memory limit, and keeps an unlimited one (#8).
- Image routes answer 415 for a source file that isn't a supported image and 404 for an unknown filter, instead of a 500 (#9).

### Removed

- Travis CI, Coveralls, `phpcs.xml` and the Prettier PHP plugin.
