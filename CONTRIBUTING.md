# Contributing

Thanks for helping! Contributions are welcome through issues and pull requests on [GitHub](https://github.com/folkloreinc/laravel-image).

- Everything in this repository is written in **English**: code, comments, commits, issues and pull requests.
- Base your work on the `v1.x` branch. The roadmap lives in [#3](https://github.com/folkloreinc/laravel-image/issues/3).
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

The S3-compatible disk tests (`tests/Feature/S3DiskTest.php`) are skipped unless `IMAGE_TEST_S3_ENDPOINT` points to an S3-compatible server, such as MinIO:

```bash
docker run -d -p 9000:9000 minio/minio server /data
IMAGE_TEST_S3_ENDPOINT=http://127.0.0.1:9000 vendor/bin/phpunit tests/Feature/S3DiskTest.php
```

A pull request is merged only when CI is green.

## Security

Please don't report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md).
