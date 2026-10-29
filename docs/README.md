# laravel-image 2

`folklore/laravel-image` renders images on the fly from a URL: presets, crops, formats and responsive variants, served once and then cached by a CDN or on a disk.

Version 2 is in development on the `v2.x` branch. Its pilot project is Niche. 1.x stays maintained on `v1.x` for fixes and security.

| Document | Content |
|---|---|
| [guides/getting-started.md](guides/getting-started.md) | Install, a first preset, the route, `image:doctor`, testing |
| [guides/presets.md](guides/presets.md) | Presets, their versions, responsive widths and formats, variants in APIs |
| [guides/urls-and-signatures.md](guides/urls-and-signatures.md) | The URL syntax, host and prefix, signed and expiring URLs, private sources, strict mode, limits |
| [guides/delivery-behind-a-cdn.md](guides/delivery-behind-a-cdn.md) | Caching headers, Cloudflare, stream or redirect, several servers |
| [current-state.md](current-state.md) | Audit of 1.x: how it works, what projects use, known limits |
| [architecture.md](architecture.md) | Design of 2.0: sources, specs, presets, URLs, rendering, storage, delivery, responsive images, testing |
| [decisions/](decisions/) | One file per decision |
| [roadmap.md](roadmap.md) | Phases and alpha slices |
| [upgrade.md](upgrade.md) | Upgrading a project from 1.x: `image:upgrade`, the legacy route, the 1.x config |

Tracking issue: [#3](https://github.com/folkloreinc/laravel-image/issues/3), the index of [roadmap.md](roadmap.md) and of 1.x. The kickoff was [#59](https://github.com/folkloreinc/laravel-image/issues/59) (closed).

Related work:

- `laravel-mediatheque` 2, which provides the media resolver: [folkloreinc/laravel-mediatheque#23](https://github.com/folkloreinc/laravel-mediatheque/issues/23) and its [package boundaries](https://github.com/folkloreinc/laravel-mediatheque/blob/v2.x/docs/decisions/0009-package-boundaries.md)
- Panneau 5 non-destructive image editing: [folkloreinc/laravel-panneau#35](https://github.com/folkloreinc/laravel-panneau/issues/35) and [panneau-js decision 0010](https://github.com/folkloreinc/panneau-js/blob/v5.x/docs/decisions/0010-media.md)
- `laravel-folklore`, whose `ImageSize` delegates to `ImageVariant`: [folkloreinc/laravel-folklore#1](https://github.com/folkloreinc/laravel-folklore/issues/1)
