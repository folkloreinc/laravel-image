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

2.x needs PHP 8.3+ and the `ffi` extension (for the libvips driver, a development dependency). The image tests run on every installed driver: GD, Imagick, and libvips (`brew install vips`, or `apt-get install libvips-tools`). Set `IMAGE_TEST_DRIVER=gd`, `imagick` or `vips` to require one, as CI does.

A pull request is merged only when CI is green.

## Releasing

1.x is released from `v1.x`, following its own guide. 2.0 is released from `v2.x` in pre-releases, then 2.0.0, on Packagist only (there is no JavaScript package in 2.x):

- **Versions:** `v2.0.0-alpha.N` for each slice of the [roadmap](docs/roadmap.md), then `v2.0.0-beta.N`, `v2.0.0-rc.N` and `v2.0.0`. Until the release candidate, an alpha can break the previous one. Projects require `^2.0@alpha` (or `@beta`, `@rc`).
- **Only from a green commit of `v2.x`:** the release workflow refuses a tag that isn't on `v2.x`, or whose commit doesn't have its `Tests passed`, `phpstan` and `pint` checks green.

Steps:

1. Open a release pull request against `v2.x` that moves the `[Unreleased]` section of `CHANGELOG.md` to a `## [2.0.0-alpha.1] - YYYY-MM-DD` section, with the **breaking changes since the previous alpha** first, and adds its link at the bottom (`[2.0.0-alpha.1]: https://github.com/folkloreinc/laravel-image/releases/tag/v2.0.0-alpha.1`). Check the roadmap boxes of the slice.
2. Merge it, and wait for CI to pass on the merge commit of `v2.x`.
3. Tag that commit and push the tag:

   ```bash
   git switch v2.x && git pull
   git tag -a v2.0.0-alpha.1 -m "v2.0.0-alpha.1"
   git push origin v2.0.0-alpha.1
   ```

4. The `release` workflow checks the tag and CI, and publishes the GitHub release with the changelog section, marked as a pre-release for alpha, beta and RC versions, so 1.x stays the latest release. Packagist picks up the tag.
5. Check that [Packagist](https://packagist.org/packages/folklore/laravel-image) lists the version, then tell the pilot project.

A tag that fails the checks publishes nothing: delete it (`git push origin :refs/tags/v2.0.0-alpha.1`), fix the cause, and tag again. Never move a tag that Packagist already has.

## Security

Please don't report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md).
