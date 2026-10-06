# Changelog

All notable changes to `folklore/laravel-image` are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- GitHub Actions: tests on PHP 8.2–8.5 × Laravel 9–13 (`prefer-lowest` and `prefer-stable`), Pint, Larastan, and the JS suite and build.
- Laravel Pint, Larastan (with a baseline) and the Composer scripts `test`, `test-coverage`, `analyse` and `format`.
- URL fixtures shared by the PHP and JS suites, and feature tests that serve images through real routes.

### Changed

- The test suite runs on Testbench 7–11 and PHPUnit 9.5–12.
- JS tooling: Jest is installed, ESLint uses a flat config, and Babel helpers are bundled.
- `composer.json`: `suggest` instead of the invalid `suggests` key, and an accurate description.

### Removed

- Travis CI, Coveralls, `phpcs.xml` and the Prettier PHP plugin.
