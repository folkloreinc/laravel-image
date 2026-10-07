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

- `v1.x` supports **PHP 8.2–8.5 and Laravel 9–13**: active client projects still run PHP 8.2 and Laravel 9. CI tests every supported combination; don't drop a version within `v1.x`.
- `v1.x` is the maintained branch. `main` and `develop` are stale; don't base work on them.
- Follow semver and tag releases (`v1.1.0`, …). Consumers should be able to require `^1.x` instead of `v1.x-dev`.

## Pull requests

Client sites install `v1.x-dev` directly, so every push to `v1.x` reaches production on their next `composer update`. **Every change goes through a pull request; never push to `v1.x` directly.**

1. Start from an issue (a sub-issue of #3, or a new issue). Read it and its comments first.
2. Branch from an up-to-date `v1.x`, named `<type>/<issue>-<slug>`: `feature/12-named-presets`, `fix/15-svg-detection`, `docs/…`, `ci/…`, `refactor/…`.
3. Commit as you go, then push the branch and open a pull request against `v1.x`, with `Closes #<issue>` (or `Part of #<issue>`) in its body. Write the title and body in English, and list the checks you ran.
4. Merge only when CI is green and the pull request is reviewed. Prefer a merge commit, so formatting-only commits keep their hash in `.git-blame-ignore-revs`.
5. Delete the branch after merging. An issue closes when its pull request merges, not when code is pushed.

Something out of scope noticed along the way becomes a new issue, not part of the current pull request.

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

CI (GitHub Actions, `.github/workflows`) runs:

- `run-tests.yml`: the PHP suite with `prefer-stable` on every supported PHP × Laravel combination, and with `prefer-lowest` on the lowest PHP version of each Laravel version, a coverage report, the S3-compatible disk tests, and a `Tests passed` job that succeeds only when all of them do;
- `pint.yml` and `phpstan.yml`: code style, static analysis and `composer validate --strict`;
- `js.yml`: lint, Jest and the Rollup build.

On a pull request, `run-tests.yml` and `js.yml` skip their suite when no file it depends on changed (`.github/scripts/affected-suites.sh` decides; `Tests passed` still reports). A file the script doesn't know runs the PHP suite. When you add a kind of file that only one suite uses, list it there. Pushes to `v1.x` run everything.

A change merges only when CI is green.

## Tooling

This package uses the tools Laravel packages usually use, so contributors find familiar commands:

| Concern         | Tool                                                         | Command                                 |
| --------------- | ------------------------------------------------------------ | --------------------------------------- |
| PHP tests       | PHPUnit with Orchestra Testbench                             | `composer test`, `composer test-coverage` |
| PHP style       | Laravel Pint (`laravel` preset, `pint.json`)                 | `composer format`                       |
| PHP analysis    | Larastan (`phpstan.neon.dist`)                               | `composer analyse`                      |
| JS tests        | Jest                                                         | `npm test`                              |
| JS style        | ESLint and Prettier (JS only)                                | `npm run lint`                          |
| CI              | GitHub Actions (`.github/workflows`)                         |                                         |

Don't add other formatters or linters for PHP, such as `phpcs` or the Prettier PHP plugin.

## Checks

Before committing, run what applies to your change:

- `composer test`, `composer analyse` and `composer format`.
- `npm test` and `npm run lint` when you touch `js/`.
- **Larastan baseline:** `phpstan-baseline.neon` lists known errors. Fixing one removes its entry (regenerate with `vendor/bin/phpstan analyse --generate-baseline`). Never add new code to the baseline: fix the error instead.
- **Formatting-only commits** (for example a new Pint rule) go in their own commit, listed in `.git-blame-ignore-revs`.
