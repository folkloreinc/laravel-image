# Roadmap of 2.0

2.0 is built on the `v2.x` branch in slices, each released as a tagged pre-release (`v2.0.0-alpha.N`, then beta and RC) on Packagist. The pilot project, Niche, requires `^2.0@alpha` and pulls each slice from its own needs. 1.x stays maintained on `v1.x` for fixes and security only; a phase 2 (v1.2) item of [#3](https://github.com/folkloreinc/laravel-image/issues/3) is done on `v1.x` only when an existing project needs it.

Tracking issues: [#3](https://github.com/folkloreinc/laravel-image/issues/3) (overall plan) and [#59](https://github.com/folkloreinc/laravel-image/issues/59) (kickoff). Each item becomes a sub-issue when its work starts.

## Rules

- **Contracts first.** Each public contract is written with its tests before its implementation, and reviewed against its consumers (`laravel-mediatheque` 2, Panneau 5, `laravel-folklore`) before the alpha that ships it.
- **Each alpha is usable end to end** for what it contains, with docs. Nothing half-done is tagged.
- **1.x URLs are never broken.** The 1.x URL fixtures run on every alpha.
- Until the release candidate, an alpha can break the previous one. Breaking changes between alphas are listed in the CHANGELOG.
- Releases are tagged from a green CI on `v2.x`.

## Phase 0: kickoff

- [x] `v2.x` branch, created from `v1.x`
- [x] `docs/`: current state, architecture, decisions, this roadmap, upgrade guide
- [x] Tooling: PSR-4 from `src/`, PHP 8.3+, Laravel 12+, CI matrix (PHP 8.3 to 8.5 × Laravel 12 and 13 × GD, Imagick and libvips), Pint, Larastan at the maximum level without a baseline for new code (#61)
- [x] Remove the 1.x code from `v2.x`, including `js/`, the npm and Babel tooling and `js.yml` ([decisions/0008](decisions/0008-no-javascript-package.md)), and start the new layout; keep the 1.x URL fixtures and README examples for the compatibility tests (#62)
- [x] Release process: changelog, `v2.0.0-alpha.N` tags from green CI, on Packagist (#64)
- [x] Contracts and their tests: `ImageSpec` and its enums, `Preset`, `UrlCodec` (v2 syntax, [decisions/0009](decisions/0009-v2-url-syntax.md); `V1Codec` in the beta, its fixtures are kept), `Signer`, `SourceResolver` and `ImageSource`, `Renderer` and `Operation`, `DerivativeStore`, `ImageVariant`, `Image::fake()` (#63)
- [x] Review the `SourceResolver` contract with `laravel-mediatheque` 2 ([review](https://github.com/folkloreinc/laravel-image/issues/59#issuecomment-6219674071)); the points that block alpha 1 are settled in [decisions/0010](decisions/0010-media-resolver.md), the others before the alpha that needs them
- [ ] Review the `transform` shape with Panneau 5 ([laravel-panneau#35](https://github.com/folkloreinc/laravel-panneau/issues/35)), before alpha 2

## Alpha 1: presets and signed URLs behind a CDN

What the pilot's media library needs first, end to end: images stored on an S3-compatible disk, resolved from a media id, served through a CDN.

- [x] `ImageSpec`, presets from the config with their version, and the `PendingImage` builder (`url()`, `variant()`, `variants()`, `render()`) (#70)
- [x] `V2Codec`, the host and the prefix (#70)
- [ ] `disk` resolver; the `media` resolver ships in `laravel-mediatheque` 2's alpha 1
- [ ] Intervention renderer with the `auto` driver (vips, imagick, gd); resize with `fit` and `ratio`; formats and quality per format
- [ ] Parity with 1.1 on every derivative: EXIF orientation, sRGB, metadata stripping, HEIC sources
- [ ] `DiskStore` on any disk, with a lock per variant
- [x] `Route::image()` (opt-in), strict mode, enforced limits, signed URLs with an expiry, private sources only through signed URLs (#71)
- [x] Caching headers: `immutable`, `ETag`, `304`; stream or redirect (#71)
- [x] `ImageVariant` and `ImageVariantResource` (#70)
- [x] `Image::fake()` with assertions (#70)
- [ ] `image:doctor`
- [ ] Docs: getting started, presets, URLs and signatures, delivery behind a CDN

## Alpha 2: channels, editing and responsive images

What the pilot's creator MVP needs.

- [ ] Channel presets in the published config: Open Graph 1200×630, square, 4:5, 9:16, email-safe JPEG
- [ ] Focal point (`focus`), `dpr`, `upscale` policy
- [ ] Source `transform` (crop, rotate, flip) in Panneau 5's shape, on the media or per usage
- [ ] With `laravel-mediatheque` 2: metadata names (orientation, ThumbHash, dominant color), the focal point and `transform` on the media, and clearing a media's derivatives when its visibility changes or its original is replaced ([decisions/0010](decisions/0010-media-resolver.md))
- [ ] Responsive presets (`widths`, `formats`), `srcset()`, `<x-image>` with `<picture>`, AVIF and WebP
- [ ] `Image::analyze()`: dimensions, dominant color, ThumbHash; placeholders on variants and in `<x-image>`
- [ ] `Image::warm()`, `image:warm`, `image:clear`, `image:presets --json`
- [ ] Events: `ImageRendered`, `ImageRenderFailed`, `DerivativesCleared`
- [ ] Docs: responsive images, transforms, testing, security, operations (drivers, libvips and FFI)

## Alpha 3: more operations

- [ ] The 1.x filters as operations: `blur`, `grayscale`, `negative`, `gamma`, `colorize`, `interlace` (progressive output)
- [ ] New operations: `sharpen`, `background` and padding, `trim`
- [ ] Animated GIF and WebP: keep the animation, or the first frame
- [ ] Custom operations through `Image::operation()`, and preset classes
- [ ] SVG: served as is, or rasterized when the driver can

## Beta: upgrade path

- [ ] Compatibility layer ([decisions/0007](decisions/0007-compatibility-layer.md)): 1.x facade methods and helpers, array filters as presets, `image.sizes` and `app.image_url`, `Image::url()` returning 1.x URLs, `$router->image()`
- [ ] `V1Codec` and `Route::imageLegacy()`, with the 1.x restrictions and `PublicPathStore`
- [ ] `image:upgrade` with `--check`
- [ ] Rector rules
- [ ] `ImageVariantResource` in the `laravel-folklore` `ImageSize` shape
- [ ] Pilot upgrades: `laravel-folklore` 2 (`ImageSize` delegates to `ImageVariant`) and one active project, following [upgrade.md](upgrade.md) as written
- [ ] [upgrade.md](upgrade.md) complete

## Release candidate

Before the pilot's launch, which needs at least an RC.

- [ ] Memory and time on very large photos, on each driver, with a published benchmark
- [ ] Security review: signatures and expiry, limits, path containment, private sources
- [ ] API frozen; every public class documented
- [ ] Docs complete, examples run as tests

## 2.0.0

- [ ] The pilot in production on an RC, and one project upgraded from 1.x
- [ ] `v2.x` becomes the default branch

## After 2.0

- Delivery drivers for external image proxies (imgproxy, Cloudflare Images)
- Pre-generation through Eclair, for applications that render presets at ingest
- Smart crops (saliency or face detection) as a focal point provider
- Watermarks and overlays
