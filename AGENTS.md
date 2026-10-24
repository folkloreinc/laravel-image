# AGENTS.md

`folklore/laravel-image` is a **public** Laravel package that renders images on the fly from a URL: presets, crops, formats and responsive variants. It is published on Packagist. `folklore/laravel-folklore` depends on it, and so do many client sites through it, along with Micromag and Niche.

**This is the `v2.x` branch**, where 2.0 is developed with Niche as the pilot project. It is the default branch. The plan is [`docs/roadmap.md`](docs/roadmap.md), indexed by [#3](https://github.com/folkloreinc/laravel-image/issues/3). 1.x is maintained on `v1.x`, whose own `AGENTS.md` describes it.

## Language

**Everything in this repository is written in English**, because the package is public:

- code, identifiers, comments and file names;
- documentation, README and changelog;
- commit messages;
- GitHub issues and their comments, pull request titles and bodies, and review comments.

This applies even when the conversation with the maintainer happens in another language (often French).

## Design and roadmap

Read [`docs/`](docs/README.md) before changing anything: the [architecture](docs/architecture.md) and the [decisions](docs/decisions/) are the rules for 2.0, and the [roadmap](docs/roadmap.md) lists the slices, named by theme.

- A decision is not edited once accepted: a new one replaces it and links to it.
- Open each piece of work as a **sub-issue of #3**, and keep the roadmap checkboxes up to date, with the release that ships each item.
- Contracts are written with their tests before their implementation, and reviewed against their consumers (`laravel-mediatheque` 2, Panneau 5, `laravel-folklore`).

## Branches and releases

- `v2.x` supports **PHP 8.3–8.5 and Laravel 12–13** ([decisions/0001](docs/decisions/0001-supported-versions.md)). CI tests every combination, on each image driver.
- `v2.x` is the development branch of 2.0 and the default branch. Composer aliases it as `2.x-dev`.
- `v1.x` stays maintained for fixes and security. A fix that applies to both lines goes to `v1.x` first.
- 2.0 ships as pre-releases (`v2.0.0-alpha.N`, then beta and RC) when a part of the roadmap is ready, on Packagist only. A tag is only pushed on a commit of `v2.x` whose CI is green; the `release` workflow checks it and publishes the GitHub release from `CHANGELOG.md`. The steps are in [CONTRIBUTING.md](CONTRIBUTING.md#releasing).
- Every change that users of an alpha will notice goes in the `[Unreleased]` section of `CHANGELOG.md`, in the same pull request, with breaking changes under **Breaking changes**.
- Other branches aren't maintained. `develop` stays only while two old projects require it; don't base work on it.

## Pull requests

Niche requires the 2.0 pre-releases, so **every change goes through a pull request; never push to `v2.x` directly.**

1. Start from an issue (a sub-issue of #3, or a new issue). Read it and its comments first.
2. Branch from an up-to-date `v2.x`, named `<type>/<issue>-<slug>`: `feature/12-named-presets`, `fix/15-svg-detection`, `docs/…`, `ci/…`, `refactor/…`.
3. Commit as you go, then push the branch and open a pull request against `v2.x`, with `Closes #<issue>` (or `Part of #<issue>`) in its body. Write the title and body in English, and list the checks you ran.
4. Merge only when CI is green and the pull request is reviewed. Prefer a merge commit, so formatting-only commits keep their hash in `.git-blame-ignore-revs`.
5. Delete the branch after merging. An issue closes when its pull request merges, not when code is pushed.

Something out of scope noticed along the way becomes a new issue, not part of the current pull request.

## Compatibility rules

- **Never break 1.x image URLs.** URLs in the `-filters(...)` format live in CDN caches, sent emails and stored HTML. 2.x and later versions keep decoding them (`V1Codec`, [decisions/0004](docs/decisions/0004-specs-and-urls.md)). Their fixtures are in `tests/fixtures/urls/v1.json` and `tests/fixtures/urls/v1-readme.json`: never remove a case.
- The 1.x API keeps working through a compatibility layer until 3.0 ([decisions/0007](docs/decisions/0007-compatibility-layer.md)).
- Until the release candidate, an alpha can break the previous one. List each breaking change in `CHANGELOG.md`.

## Repository map

```
src/                  PHP package, PSR-4 `Folklore\\Image\\` (layout in docs/architecture.md)
src/Contracts/        the public contracts; src/Testing/Contracts/ has the tests every implementation runs
tests/                PHPUnit; tests/Doubles/ has test implementations of the contracts
tests/fixtures/       JSON fixtures (urls/v1.json, urls/v2.json, specs.json, signatures.json) and test images (images/)
docs/                 architecture, decisions, roadmap, upgrade guide; docs/guides/ has the user guides
```

There is no JavaScript package in 2.x ([decisions/0008](docs/decisions/0008-no-javascript-package.md)).

## Tests and CI

Every change ships with automated tests:

- A bug fix adds a **regression test that fails without the fix** and passes with it.
- A new option or feature is tested in both states (enabled and disabled, allowed and denied), through a real HTTP route when it affects serving images.
- A change to URL encoding or decoding updates the URL fixtures in `tests/fixtures/urls/`. A valid case of `v2.json` is never changed or removed once alpha 1 is tagged ([decisions/0009](docs/decisions/0009-v2-url-syntax.md)).
- An implementation of a contract (a resolver, a renderer, a store, an operation) uses its trait from `src/Testing/Contracts/`.

CI (GitHub Actions, `.github/workflows`) runs:

- `run-tests.yml`: the PHP suite with `prefer-stable` on every supported PHP × Laravel combination and on each image driver (GD, Imagick, and libvips with FFI enabled), with `prefer-lowest` on the lowest PHP version of each Laravel version, a coverage report, and a `Tests passed` job that succeeds only when all of them do;
- `pint.yml` and `phpstan.yml`: code style, static analysis, `composer validate --strict` and the PSR-4 autoload.
- `release.yml`, on `v2.*` tags: checks that the tag is on `v2.x` and its CI green (`.github/scripts/check-green.sh`), and publishes the GitHub release with the changelog section (`.github/scripts/release-notes.sh`).

Each test job sets `IMAGE_TEST_DRIVER` to its driver. Image tests get their driver from `TestCase::driverOrSkip()`: a driver that isn't installed is skipped, except the one under test, which fails the job. Locally, without `IMAGE_TEST_DRIVER`, every installed driver is tested.

On a pull request, `run-tests.yml` skips the suite when no file it depends on changed (`.github/scripts/affected-suites.sh` decides; `Tests passed` still reports). A file the script doesn't know runs the suite.

A change merges only when CI is green.

## Tooling

This package uses the tools Laravel packages usually use, so contributors find familiar commands:

| Concern      | Tool                                          | Command                                   |
| ------------ | --------------------------------------------- | ----------------------------------------- |
| Tests        | PHPUnit with Orchestra Testbench              | `composer test`, `composer test-coverage` |
| Style        | Laravel Pint (`laravel` preset, `pint.json`)  | `composer format`                         |
| Analysis     | Larastan at the maximum level (`phpstan.neon.dist`) | `composer analyse`                  |
| CI           | GitHub Actions (`.github/workflows`)          |                                           |

Don't add other formatters or linters, such as `phpcs` or the Prettier PHP plugin.

## Checks

Before committing, run `composer test`, `composer analyse` and `composer format`.

- **Larastan** runs at the maximum level on `src/` and `tests/`, with **no baseline**. Fix an error instead of ignoring it; don't add a baseline, `@phpstan-ignore` comments or casts that only silence it.
- **Code style:** Pint's `laravel` preset, with `declare(strict_types=1)` in every file.
- **Drivers:** to test the libvips driver locally, install libvips (`brew install vips`, `apt-get install libvips-tools`) and keep FFI enabled.

- **Formatting-only commits** (for example a new Pint rule) go in their own commit, listed in `.git-blame-ignore-revs`.
