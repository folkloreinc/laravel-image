# Laravel Image

[![Tests](https://github.com/folkloreinc/laravel-image/actions/workflows/run-tests.yml/badge.svg?branch=v2.x)](https://github.com/folkloreinc/laravel-image/actions/workflows/run-tests.yml?query=branch%3Av2.x)

On-the-fly image renditions for Laravel: presets, crops, formats and responsive variants, rendered once and served behind a CDN.

> **This is the `v2.x` branch, where 2.0 is developed, and the default branch.** 2.0 is released as alphas (`composer require folklore/laravel-image:^2.0@alpha`, PHP 8.3+ and Laravel 12+); an alpha can break the previous one, so read the [CHANGELOG](CHANGELOG.md) before you update. 1.x is maintained on [`v1.x`](https://github.com/folkloreinc/laravel-image/tree/v1.x): `composer require folklore/laravel-image:^1.1`.

## 2.0

2.0 is built on [Intervention Image](https://image.intervention.io) 4, with libvips when available:

```php
Image::for($media)->preset('card')->url();
Image::for('uploads/photo.jpg')->width(800)->ratio('16:9')->avif()->url();
Image::for($media)->variants('card'); // width, height and URL of each variant, for APIs
```

**In the alphas today:**

- Presets and canonical specs, in versioned URLs that change when a preset or an original changes.
- Secure by default: opt-in routes (`Route::image()`), presets or signed URLs only, enforced limits, private images through expiring URLs.
- Derivatives rendered once with libvips, Imagick or GD, stored on any Laravel disk with a lock per variant, served with long-lived caching headers behind a CDN.
- Sources resolved from disks, and from media ids with `laravel-mediatheque` 2's `media` resolver.
- `ImageVariantResource`, `Image::fake()` and `image:doctor`.

**Planned** ([roadmap](docs/roadmap.md)): focal point and non-destructive edits (`transform`), channel presets, responsive images with `srcset()` and `<x-image>`, more operations, and for the beta, a compatibility layer that keeps 1.x URLs and the 1.x API working until 3.0.

The guides of the alpha are in [`docs/guides/`](docs/guides/getting-started.md): [getting started](docs/guides/getting-started.md), [presets](docs/guides/presets.md), [URLs and signatures](docs/guides/urls-and-signatures.md) and [delivery behind a CDN](docs/guides/delivery-behind-a-cdn.md).

The plan is in [`docs/`](docs/README.md): the [architecture](docs/architecture.md), the [decisions](docs/decisions/), the [roadmap](docs/roadmap.md) and the [upgrade guide](docs/upgrade.md). [#3](https://github.com/folkloreinc/laravel-image/issues/3) is the index of the plan and tracks 1.x.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow, and [SECURITY.md](SECURITY.md) to report a vulnerability privately.

## License

MIT. See [LICENSE.md](LICENSE.md).
