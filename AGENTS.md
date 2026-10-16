# AGENTS.md

`folklore/laravel-image` is a **public** Laravel package that renders images on the fly from a URL: presets, crops, formats and responsive variants. It is published on Packagist. `folklore/laravel-folklore` depends on it, and so do many client sites through it, along with Micromag and Niche.

**This is the `v2.x` branch**, where 2.0 is developed with Niche as the pilot project ([#59](https://github.com/folkloreinc/laravel-image/issues/59)). 1.x is maintained on `v1.x`, whose own `AGENTS.md` describes it.

## Language

**Everything in this repository is written in English**, because the package is public:

- code, identifiers, comments and file names;
- documentation, README and changelog;
- commit messages;
- GitHub issues and their comments, pull request titles and bodies, and review comments.

This applies even when the conversation with the maintainer happens in another language (often French).

## Design and roadmap

Read [`docs/`](docs/README.md) before changing anything: the [architecture](docs/architecture.md) and the [decisions](docs/decisions/) are the rules for 2.0, and the [roadmap](docs/roadmap.md) lists the phases and alpha slices.

- A decision is not edited once accepted: a new one replaces it and links to it.
- Open each piece of work as a **sub-issue of #59** (phase 0) or of the alpha it belongs to, and keep the roadmap checkboxes up to date.
- Contracts are written with their tests before their implementation, and reviewed against their consumers (`laravel-mediatheque` 2, Panneau 5, `laravel-folklore`).

## Branches and releases

- `v2.x` is the development branch of 2.0 until 2.0.0, then becomes the default branch.
- `v1.x` stays maintained for fixes and security. A fix that applies to both lines goes to `v1.x` first.
- `main` and `develop` are stale; don't base work on them.

## Pull requests

Niche requires the 2.0 pre-releases, so **every change goes through a pull request; never push to `v2.x` directly.**

1. Start from an issue (a sub-issue of #59, or a new issue). Read it and its comments first.
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
src/                  PHP package, PSR-4 (empty until the 2.0 contracts land; layout in docs/architecture.md)
tests/                PHPUnit
tests/fixtures/       JSON fixtures (urls/) and test images (images/)
docs/                 architecture, decisions, roadmap, upgrade guide
```

There is no JavaScript package in 2.x ([decisions/0008](docs/decisions/0008-no-javascript-package.md)).

## Tests and CI

Every change ships with automated tests:

- A bug fix adds a **regression test that fails without the fix** and passes with it.
- A new option or feature is tested in both states (enabled and disabled, allowed and denied), through a real HTTP route when it affects serving images.
- A change to URL encoding or decoding updates the URL fixtures in `tests/fixtures/urls/`.

CI (GitHub Actions, `.github/workflows`) runs:

- `run-tests.yml`: the PHP suite on every supported PHP × Laravel combination, a coverage report, and a `Tests passed` job that succeeds only when all of them do;
- `pint.yml` and `phpstan.yml`: code style, static analysis and `composer validate --strict`.

On a pull request, `run-tests.yml` skips the suite when no file it depends on changed (`.github/scripts/affected-suites.sh` decides; `Tests passed` still reports). A file the script doesn't know runs the suite.

A change merges only when CI is green.

## Tooling

This package uses the tools Laravel packages usually use, so contributors find familiar commands:

| Concern      | Tool                                          | Command                                   |
| ------------ | --------------------------------------------- | ----------------------------------------- |
| Tests        | PHPUnit with Orchestra Testbench              | `composer test`, `composer test-coverage` |
| Style        | Laravel Pint (`laravel` preset, `pint.json`)  | `composer format`                         |
| Analysis     | Larastan (`phpstan.neon.dist`)                | `composer analyse`                        |
| CI           | GitHub Actions (`.github/workflows`)          |                                           |

Don't add other formatters or linters, such as `phpcs` or the Prettier PHP plugin.

## Checks

Before committing, run `composer test`, `composer analyse` and `composer format`.

- **Formatting-only commits** (for example a new Pint rule) go in their own commit, listed in `.git-blame-ignore-revs`.
