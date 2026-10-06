# AGENTS.md

`folklore/laravel-image` is a **public** Laravel package for URL-based image manipulation, built on [Imagine](https://github.com/php-imagine/Imagine). It is published on Packagist (PHP) and npm (`laravel-image`, the JS URL generator). `folklore/laravel-folklore` depends on it, and so do many client sites through it, along with Micromag and Niche.

## Language

**Everything in this repository is written in English**, because the package is public:

- code, identifiers, comments and file names;
- documentation, README and changelog;
- commit messages;
- GitHub issues and their comments, pull request titles and bodies, and review comments.

This applies even when the conversation with the maintainer happens in another language (often French).

## Roadmap

The plan lives in [#3](https://github.com/folkloreinc/laravel-image/issues/3): phase 1 stabilizes v1.1, phase 2 simplifies v1.2, and phase 3 modernizes v2. Open each piece of work as a **sub-issue of #3**, and update #3 when the scope changes.

## Branches and releases

- `v1.x` is the maintained branch. `main` and `develop` are stale; don't base work on them.
- Follow semver and tag releases (`v1.1.0`, …). Consumers should be able to require `^1.x` instead of `v1.x-dev`.

## Compatibility rules

Many sites run this package in production, so within `v1.x`:

- **Never break existing image URLs.** URLs in the `-filters(...)` format live in CDN caches, sent emails and stored HTML, and must keep being parsed (in v2 too).
- Keep public signatures and behaviour stable:
    - `Image::url()`, `Image::make()`, `Image::source()`, `Image::filter()`;
    - the `image()` and `image_url()` helpers;
    - the `Image` facade;
    - the `$router->image()` macro;
    - the existing config keys.
- Changes must be additive. A new restriction, or an option that was documented but never enforced, ships in a log-only mode first, then gets enforced.
- The PHP (`src/Folklore/Image/UrlGenerator.php`) and JS (`js/src/UrlGenerator.js`) URL generators must produce the same URLs for the same input.

## Repository map

```
src/Folklore/Image/   PHP package (service provider, URL generator, sources, filters, HTTP)
src/config/image.php  Default config, published to config/image.php
src/routes/images.php Default image route, published to routes/images.php
js/src/               JS URL generator (npm package)
tests/                PHPUnit (Unit, Feature); fixtures in tests/fixture
```

## Tests and CI

Every change ships with automated tests:

- A bug fix adds a **regression test that fails without the fix** and passes with it.
- A new option or feature is tested in both states (enabled and disabled, allowed and denied), through a real HTTP route when it affects serving images.
- A change to URL generation or parsing updates the URL fixtures shared by the PHP and JS suites, so both generators stay in sync.

CI (GitHub Actions, set up in Phase 1, Step 1 of #3) runs the PHP suite on every supported PHP × Laravel combination, plus the JS suite and static analysis. A change merges only when CI is green. Until that step lands, work on CI and on fixing the existing tests comes before any other Phase 1 item.

## Checks

- PHP: `composer install`, then `vendor/bin/phpunit`.
- JS: `npm install`, then `npm test` (Jest).
- Until Phase 1, Step 1 of #3 fixes the suite, some PHP tests already fail on `v1.x`. Meanwhile, a change passes when it adds no new failure and includes its own tests.
- Style: PSR-2 (`phpcs.xml`), 4-space indentation (`.editorconfig`). Match the surrounding code.
