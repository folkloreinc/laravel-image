# Laravel Image

[![Tests](https://github.com/folkloreinc/laravel-image/actions/workflows/run-tests.yml/badge.svg?branch=v2.x)](https://github.com/folkloreinc/laravel-image/actions/workflows/run-tests.yml?query=branch%3Av2.x)

On-the-fly image renditions for Laravel: presets, crops, formats and responsive variants, rendered once and served behind a CDN.

> **This is the `v2.x` branch, where 2.0 is in development.** It has no usable code yet. For the current release, see [`v1.x`](https://github.com/folkloreinc/laravel-image/tree/v1.x) and `composer require folklore/laravel-image:^1.1`.

## 2.0

2.0 is built on [Intervention Image](https://image.intervention.io) 4, with libvips when available:

```php
Image::for($media)->preset('card')->url();
Image::for('uploads/photo.jpg')->width(800)->ratio('16:9')->avif()->url();
Image::for($media)->variants('card'); // width, height and URL of each variant, for APIs
```

```blade
<x-image :src="$media" preset="card" sizes="(min-width: 768px) 50vw, 100vw" alt="…" />
```

- Presets and canonical specs, in versioned URLs that change when a preset or an original changes.
- Secure by default: opt-in routes, presets or signed URLs only, enforced limits, private images through expiring URLs.
- Derivatives rendered once, stored on any Laravel disk, served with long-lived caching headers behind a CDN.
- Sources resolved from paths, disks or media ids, with their dimensions, focal point and non-destructive edits.
- 1.x URLs keep working, and a compatibility layer keeps the 1.x API until 3.0.

The plan is in [`docs/`](docs/README.md): the [architecture](docs/architecture.md), the [decisions](docs/decisions/), the [roadmap](docs/roadmap.md) and the [upgrade guide](docs/upgrade.md). Progress is tracked in [#59](https://github.com/folkloreinc/laravel-image/issues/59).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow, and [SECURITY.md](SECURITY.md) to report a vulnerability privately.

## License

MIT. See [LICENSE.md](LICENSE.md).
