# Roadmap of 2.0

2.0 is built on the `v2.x` branch in slices named by theme. Pre-releases (`v2.0.0-alpha.N`, then beta and RC) are tagged on Packagist when a part is ready, and each item says the release that shipped it; what each release contains is in the [CHANGELOG](../CHANGELOG.md). The pilot project, Niche, runs `^2.0@alpha` and pulls the slices from its own needs. 1.x stays maintained on `v1.x` for fixes and security only; a phase 2 (v1.2) item of [#3](https://github.com/folkloreinc/laravel-image/issues/3) is done on `v1.x` only when an existing project needs it.

Index: [#3](https://github.com/folkloreinc/laravel-image/issues/3). Each item becomes a sub-issue of it when its work starts. The kickoff was [#59](https://github.com/folkloreinc/laravel-image/issues/59) (closed).

## Rules

- **Contracts first.** Each public contract is written with its tests before its implementation, and reviewed against its consumers (`laravel-mediatheque` 2, Panneau 5, `laravel-folklore`) before the alpha that ships it.
- **Each pre-release is usable end to end** for what it contains, with docs. Nothing half-done is tagged.
- **1.x URLs are never broken.** The 1.x URL fixtures run on every alpha.
- Until the release candidate, an alpha can break the previous one. Breaking changes between alphas are listed in the CHANGELOG.
- Releases are tagged from a green CI on `v2.x`.

## Kickoff (done)

- [x] `v2.x` branch, created from `v1.x`
- [x] `docs/`: current state, architecture, decisions, this roadmap, upgrade guide
- [x] Tooling: PSR-4 from `src/`, PHP 8.3+, Laravel 12+, CI matrix (PHP 8.3 to 8.5 × Laravel 12 and 13 × GD, Imagick and libvips), Pint, Larastan at the maximum level without a baseline (#61)
- [x] Remove the 1.x code from `v2.x`, including `js/`, the npm and Babel tooling and `js.yml` ([decisions/0008](decisions/0008-no-javascript-package.md)), and start the new layout; keep the 1.x URL fixtures and README examples for the compatibility tests (#62)
- [x] Release process: changelog, `v2.0.0-alpha.N` tags from green CI, on Packagist (#64)
- [x] Contracts and their tests: `ImageSpec` and its enums, `Preset`, `UrlCodec` (v2 syntax, [decisions/0009](decisions/0009-v2-url-syntax.md); `V1Codec` in the beta, its fixtures are kept), `Signer`, `SourceResolver` and `ImageSource`, `Renderer` and `Operation`, `DerivativeStore`, `ImageVariant`, `Image::fake()` (#63)
- [x] Review the `SourceResolver` contract with `laravel-mediatheque` 2 ([review](https://github.com/folkloreinc/laravel-image/issues/59#issuecomment-6219674071)); the points that blocked 2.0.0-alpha.1 are settled in [decisions/0010](decisions/0010-media-resolver.md), the others in [#88](https://github.com/folkloreinc/laravel-image/issues/88)

## Presets and signed URLs behind a CDN (2.0.0-alpha.1)

What the pilot's media library needs first, end to end: images stored on an S3-compatible disk, resolved from a media id, served through a CDN.

Released as [v2.0.0-alpha.1](https://github.com/folkloreinc/laravel-image/releases/tag/v2.0.0-alpha.1) on 2026-10-16. The `media` resolver shipped in `laravel-mediatheque` [v2.0.0-alpha.2](https://github.com/folkloreinc/laravel-mediatheque/releases/tag/v2.0.0-alpha.2).

- [x] `ImageSpec`, presets from the config with their version, and the `PendingImage` builder (`url()`, `variant()`, `variants()`, `render()`) (#70)
- [x] `V2Codec`, the host and the prefix (#70)
- [x] `disk` resolver; the `media` resolver is `laravel-mediatheque` 2's (#76)
- [x] Intervention renderer with the `auto` driver (vips, imagick, gd); resize with `fit` and `ratio`; formats and quality per format (#74)
- [x] Parity with 1.1 on every derivative: EXIF orientation, sRGB, metadata stripping, HEIC sources (#75)
- [x] `DiskStore` on any disk, with a lock per variant (#77)
- [x] `Route::image()` (opt-in), strict mode, enforced limits, signed URLs with an expiry, private sources only through signed URLs (#71)
- [x] Caching headers: `immutable`, `ETag`, `304`; stream or redirect (#71)
- [x] `ImageVariant` and `ImageVariantResource` (#70)
- [x] `Image::fake()` with assertions (#70)
- [x] `image:doctor` (#72)
- [x] Docs: getting started, presets, URLs and signatures, delivery behind a CDN (#73)

## Fixes to 2.0.0-alpha.1 (next)

- [x] `Image::routes()` mounts the 1.x route only; the v2 route is `Route::image()` ([#95](https://github.com/folkloreinc/laravel-image/issues/95), [decisions/0011](decisions/0011-image-routes-is-the-1x-route.md)). Breaking for whoever calls `Image::routes()` on alpha.1
- [x] Serve a stored derivative of an earlier preset version, as decisions 0004 and 0005 say ([#96](https://github.com/folkloreinc/laravel-image/issues/96), [decisions/0012](decisions/0012-earlier-preset-versions.md))
- [ ] Convert to sRGB after resizing, as #92 did on 1.x ([#93](https://github.com/folkloreinc/laravel-image/issues/93))
- [ ] Declare the `illuminate/*` components and Flysystem the code uses ([#94](https://github.com/folkloreinc/laravel-image/issues/94))

## Channels, editing and responsive images

What the pilot's creator MVP needs.

- [ ] First, agree on the `transform`, the focal point and the image metadata with `laravel-mediatheque` 2 and Panneau 5, and record it in a decision ([#88](https://github.com/folkloreinc/laravel-image/issues/88)); the mediatheque's side is folkloreinc/laravel-mediatheque#107
- [ ] Channel presets in the published config: Open Graph 1200×630, square, 4:5, 9:16, email-safe JPEG
- [ ] Focal point (`focus`), `dpr`, `upscale` policy
- [ ] Source `transform` (crop, rotate, flip) in Panneau 5's shape (`flipX`, `flipY`), on the media or per usage, with a per-usage transform over the media's own ([#90](https://github.com/folkloreinc/laravel-image/issues/90)); the focal point mapped from the upright original into the transformed image (#88)
- [ ] With `laravel-mediatheque` 2: the `orientation` key, the focal point and `transform` on the media, and clearing a media's derivatives when its visibility changes or its original is replaced ([decisions/0010](decisions/0010-media-resolver.md), #88). The ThumbHash key is settled (`thumbhash`)
- [ ] Responsive presets (`widths`, `formats`), `srcset()`, `<x-image>` with `<picture>`, AVIF and WebP
- [ ] `Image::analyze()`: dimensions and ThumbHash (the dominant color comes from the media library, #88); placeholders on variants and in `<x-image>`
- [ ] `Image::warm()`, `image:warm`, `image:clear`, `image:presets --json`
- [ ] Events: `ImageRendered`, `ImageRenderFailed`, `DerivativesCleared`
- [ ] Docs: responsive images, transforms, testing, security, operations (drivers, libvips and FFI)

## More operations

- [ ] The 1.x filters as operations: `blur`, `grayscale`, `negative`, `gamma`, `colorize`, `interlace` (progressive output)
- [ ] New operations: `sharpen`, `background` and padding, `trim`
- [ ] Animated GIF and WebP: keep the animation, or the first frame
- [ ] Custom operations through `Image::operation()`, and preset classes
- [ ] SVG: served as is, or rasterized when the driver can

## Beta: upgrade path

Every client site still requires `v1.x-dev` (2026-10-20). The upgrade guide starts from a tagged 1.x release, so sites move to `^1.1` first.

- [ ] Compatibility layer ([decisions/0007](decisions/0007-compatibility-layer.md)): 1.x facade methods and helpers, array filters as presets, `image.sizes` and `app.image_url`, `Image::url()` returning 1.x URLs, `$router->image()`
- [ ] `V1Codec` and `Route::imageLegacy()`, with the 1.x restrictions and `PublicPathStore`
- [ ] `image:upgrade` with `--check`
- [ ] Rector rules
- [ ] `Image::routes()` and `$router->image()` mount the legacy route (#95)
- [ ] One active project upgrades, following [upgrade.md](upgrade.md) as written. `laravel-folklore` 2 already builds its `ImageSize` on `variant()`
- [ ] [upgrade.md](upgrade.md) complete

## Release candidate

Before the pilot's launch, which needs at least an RC.

- [ ] Memory and time on very large photos, on each driver, with a published benchmark
- [ ] Security review: signatures and expiry, limits, path containment, private sources
- [ ] API frozen; every public class documented
- [ ] Docs complete, examples run as tests

## 2.0.0

- [ ] The pilot in production on an RC, and one project upgraded from 1.x

## After 2.0

- Delivery drivers for external image proxies (imgproxy, Cloudflare Images)
- Pre-generation through Eclair, for applications that render presets at ingest
- Smart crops (saliency or face detection) as a focal point provider
- Watermarks and overlays
