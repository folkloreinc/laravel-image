# Contributing

Thanks for helping! Contributions are welcome through issues and pull requests on [GitHub](https://github.com/folkloreinc/laravel-image).

- Everything in this repository is written in **English**: code, comments, commits, issues and pull requests.
- 2.0 is developed on the `v2.x` branch: base your work on it and open a pull request against it; nothing is pushed to `v2.x` directly. Name your branch `<type>/<issue>-<slug>` (for example `fix/15-svg-detection`). The plan is in [`docs/`](docs/README.md) and [#59](https://github.com/folkloreinc/laravel-image/issues/59).
- Fixes for 1.x go to the `v1.x` branch, which has its own guide.
- Never break 1.x image URLs. See the compatibility rules in [AGENTS.md](AGENTS.md).

## Tests and checks

Every change ships with tests. A bug fix adds a test that fails without the fix.

```bash
composer install
composer test        # PHPUnit
composer analyse     # Larastan
composer format      # Laravel Pint
```

A pull request is merged only when CI is green.

## Releasing

Maintainers release from `v1.x` (1.x) and `v2.x` (2.0 pre-releases), following [Semantic Versioning](https://semver.org/):

1. Open a release pull request that moves the `[Unreleased]` section of `CHANGELOG.md` to the new version with its date, with upgrade notes when sites will see a change.
2. Once it's merged and CI is green, tag the merge commit (`git tag v1.2.0 && git push origin v1.2.0`) and publish a GitHub release with the changelog section. Packagist picks up the tag.

There is no JavaScript package in 2.x. The npm package `laravel-image` (1.0.x) is published from `v1.x` only.

## Security

Please don't report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md).
