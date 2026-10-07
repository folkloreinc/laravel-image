# Contributing

Thanks for helping! Contributions are welcome through issues and pull requests on [GitHub](https://github.com/folkloreinc/laravel-image).

- Everything in this repository is written in **English**: code, comments, commits, issues and pull requests.
- Base your work on the `v1.x` branch and open a pull request against it; nothing is pushed to `v1.x` directly. Name your branch `<type>/<issue>-<slug>` (for example `fix/15-svg-detection`). The roadmap lives in [#3](https://github.com/folkloreinc/laravel-image/issues/3).
- Don't break existing image URLs or public APIs within `v1.x`. See the compatibility rules in [AGENTS.md](AGENTS.md).

## Tests and checks

Every change ships with tests. A bug fix adds a test that fails without the fix.

```bash
composer install
composer test        # PHPUnit
composer analyse     # Larastan
composer format      # Laravel Pint

npm install
npm test             # Jest
npm run lint         # ESLint and Prettier
```

The S3-compatible disk tests (`tests/Feature/S3DiskTest.php`) are skipped unless `IMAGE_TEST_S3_ENDPOINT` points to an S3-compatible server, such as [moto](https://github.com/getmoto/moto) (what CI uses) or MinIO:

```bash
pip install "moto[server]" && moto_server -p 9000 &
IMAGE_TEST_S3_ENDPOINT=http://127.0.0.1:9000 vendor/bin/phpunit tests/Feature/S3DiskTest.php
```

A pull request is merged only when CI is green.

## Releasing

Maintainers release from `v1.x`, following [Semantic Versioning](https://semver.org/) (`v1.x` never breaks existing URLs or public APIs):

1. Open a release pull request that moves the `[Unreleased]` section of `CHANGELOG.md` to the new version with its date, with upgrade notes when sites will see a change, and bumps `version` in `package.json`.
2. Once it's merged and CI is green on `v1.x`, tag the merge commit (`git tag v1.2.0 && git push origin v1.2.0`) and publish a GitHub release with the changelog section. Packagist picks up the tag.
3. Publish the JS package from the tag: `npm publish`.

## Security

Please don't report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md).
