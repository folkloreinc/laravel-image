# Architecture of 2.0

Status: accepted direction for 2.0. The examples show the intended API. The contracts are written (`src/Contracts/`, with their tests), and the v2 URL syntax is settled by [decisions/0009](decisions/0009-v2-url-syntax.md); the builder and the implementations land in the alphas ([roadmap.md](roadmap.md)).

## Goals

- **Easy:** a preset and a reference give a URL, a variant list or responsive markup in one line. Defaults are safe and good-looking.
- **Powerful:** focal points, ratios, non-destructive edits, modern formats, placeholders, custom operations and resolvers.
- **Robust:** canonical specs, versioned URLs, one render per variant, enforced limits, tested on every driver.
- **Modern:** typed, immutable value objects and enums, Intervention Image 4 with libvips.
- **Easy to upgrade:** 1.x URLs keep working, and a compatibility layer keeps the 1.x API until 3.0.

## Scope

| Concern | Owner |
|---|---|
| Image renditions on the fly: presets, crops, formats, responsive variants, their URLs and their delivery | This package |
| Analysis of an image (dimensions, dominant color, placeholder), on request | This package |
| Ingest, storage of originals, probing at ingest, visibility, media ids and references | `laravel-mediatheque` 2, which provides the `media` resolver |
| Admin UI for crops and focal points | Panneau 5, which stores a `transform` this package applies |
| Shared processing server (video, audio…) | Eclair. On-the-fly images stay here |
| Who may see a private image | The application |

## Overview

```
  reference ──▶ SourceResolver ──▶ ImageSource { key, version, disk/path, metadata, transform, private }
  (path, media,                          │
   media://…)                            ▼
                     Image::for($ref)->preset('card')->webp()   (PendingImage, immutable)
                                         │
                       ┌─────────────────┼──────────────────────┐
                       ▼                 ▼                      ▼
                   url()            variants()             render() / store()
                       │          ImageVariant[]              in code
                       ▼
           UrlCodec + Signer: /img/{signature}/{spec}/{resolver}/{key}.{format}
                       │
                       ▼  (CDN miss)
  ImageController ─▶ decode ─▶ verify (strict mode, signature, expiry, limits)
                 ─▶ resolve source ─▶ DerivativeStore hit? ──yes──▶ stream / redirect
                                              │ no
                                              ▼
                                  lock ─▶ Renderer (Intervention: vips | imagick | gd)
                                       ─▶ DerivativeStore put ─▶ stream, events
```

## Using it

### Building images

```php
use Folklore\Image\Facades\Image;

Image::for($media)->preset('card')->url();
Image::for('uploads/photo.jpg')->width(800)->ratio('16:9')->focus(0.3, 0.6)->avif()->url();
Image::for($media)->preset('og')->signed(expires: now()->addHour())->url();

Image::for($media)->variants('card');      // Collection<ImageVariant>
Image::for($media)->srcset('card');        // "…/card.3f9a1c,w400,v-8c1f/… 400w, … 800w, … 1200w"
Image::for($media)->preset('card')->variant(); // one ImageVariant

Image::for('uploads/photo.jpg')->width(300)->render();          // RenderedImage, in code
Image::for('uploads/photo.jpg')->preset('email')->store('public', 'emails/hero.jpg');
```

`Image::for()` returns an immutable `PendingImage`: every method returns a new instance, so a base can be shared (`$base = Image::for($media)->focus(...)`).

### Responsive images

```blade
<x-image :src="$media" preset="card" sizes="(min-width: 768px) 50vw, 100vw" alt="…" />
```

It renders a `<picture>` with AVIF and WebP `<source>` elements, an `<img>` fallback in the original's format family (JPEG or PNG), `width` and `height` from the source metadata, `loading="lazy"` and `decoding="async"` by default, and the placeholder as a background when known.

### In APIs

`ImageVariant` is a readonly value object, serializable to JSON:

```json
{ "preset": "card", "url": "https://img.example.com/img/_/card.3f9a1c,v-8c1f/media/med_01JAB3….webp", "width": 800, "height": 450, "mime": "image/webp" }
```

`ImageVariantResource` gives the same shape, and keeps the `{ id, url, width, height, mime }` shape of `laravel-folklore`'s `ImageSize` when asked (`->legacy()`). Panneau 5's media items expose `variants` as a map of named sizes built from it.

## Concepts

### Sources

See [decisions/0006](decisions/0006-sources-and-resolvers.md). A reference becomes an `ImageSource` through the first resolver that supports it. Resolvers are registered in the config or with `Image::resolver()`.

```php
interface SourceResolver
{
    public function name(): string;
    public function supports(mixed $reference): bool;
    public function resolve(mixed $reference): ImageSource;
    public function resolveKey(string $key): ImageSource;
}
```

- `disk`: a Laravel disk and a path, with path containment. The URL key is the path; the version is the file's last modification time (or its ETag on S3-compatible disks), cached.
- `media` (in `laravel-mediatheque` 2): `med_…`, `media://med_…` or a media model. The URL key is the `uid`; dimensions, focal point, placeholder and visibility come from the media.

### Specs

`ImageSpec` is the canonical description of one rendering ([decisions/0004](decisions/0004-specs-and-urls.md)):

| Part | Options |
|---|---|
| Transform (applied first, in source pixels) | `crop` (x, y, width, height), `rotate` (0, 90, 180, 270), `flip` (horizontal, vertical). From the source's `transform` by default |
| Resize | `width`, `height`, `fit` (`Fit` enum: `cover`, `contain`, `inside`, `fill`), `ratio`, `focus` (x, y from 0 to 1, from the source's focal point by default), `dpr`, `upscale` (from the config, never from a URL) |
| Operations | `blur`, `grayscale`, `sharpen`, `background`, … and custom ones, in a canonical order |
| Output | `format` (`Format` enum: `jpeg`, `png`, `gif`, `webp`, `avif`, or `auto` from the source), `quality` (per format from the config by default) |

Orientation, sRGB conversion and metadata stripping always apply, before the transform.

### Presets

```php
// config/image.php
'presets' => [
    'card' => ['width' => 800, 'ratio' => '16:9', 'fit' => 'cover', 'widths' => [400, 800, 1200], 'formats' => ['avif', 'webp']],
    'og' => ['width' => 1200, 'height' => 630, 'fit' => 'cover', 'format' => 'jpeg'],
    'square' => ['width' => 1080, 'ratio' => '1:1'],
    'portrait' => ['width' => 1080, 'ratio' => '4:5'],
    'story' => ['width' => 1080, 'ratio' => '9:16'],
    'email' => ['width' => 600, 'format' => 'jpeg', 'quality' => 80],
    'thumbnail' => ['width' => 200, 'height' => 200, 'fit' => 'cover'],
],
```

- A preset is a spec plus optional responsive `widths` and `formats`. It can also be a class (`implements Preset`) for presets that depend on the source.
- Its **version** is a short hash of its definition (6 hex characters), part of its URLs. A preset URL can only add one of the preset's widths; other options make a free spec, which strict mode only renders signed ([decisions/0009](decisions/0009-v2-url-syntax.md)).
- The package ships the channel presets above, commented in the published config.
- `php artisan image:presets --json` lists them with their versions, for tooling and docs.

### URLs and signatures

- `UrlCodec` encodes an `ImageUrl` (a source, a preset or a free spec, a version, an expiry, a signature) into a path, and decodes a path back, in one spelling only. `V2Codec` is the default ([decisions/0009](decisions/0009-v2-url-syntax.md)); `V1Codec` decodes 1.x URLs.
- The host comes from `image.url.host` (a CDN host), and can be overridden per request for multi-domain sites.
- `Signer` signs the path after the signature (which includes the expiry) with HMAC-SHA256 and rotating keys. Unsigned URLs use `_` in place of the signature.

### Rendering

- `Renderer` turns an `ImageSource` and an `ImageSpec` into a `RenderedImage` (contents or stream, mime, width, height). The default renderer uses Intervention Image 4 ([decisions/0002](decisions/0002-rendering-engine.md)), with the `auto` driver.
- Operations implement `Operation`. Custom ones are registered by name:

  ```php
  Image::operation('duotone', Duotone::class);
  ```

- HEIC, AVIF and WebP sources are read when the driver supports them. Animated GIF and WebP keep their animation when the output format allows it, or use their first frame.
- The memory limit is raised (never lowered) while rendering, as in 1.1.

### Storage and delivery

See [decisions/0003](decisions/0003-delivery.md).

- `DerivativeStore`: `DiskStore` (any Laravel disk, the default), `PublicPathStore` (the 1.x static cache), `NullStore` (tests, previews). Derivatives are stored under `{resolver}/{source key}/{source version}/{spec}.{ext}` (`DerivativeKey`), so `image:clear {reference}` removes all of a source's derivatives.
- Rendering takes a lock on the derivative key (`Cache::lock()`), so concurrent misses render once.
- The controller streams the derivative, or redirects to the disk URL (`image.delivery.serve`), with long-lived caching headers, an `ETag` and `304` answers.
- `Image::warm($reference, $presets)` queues the rendering of presets ahead of time; `image:warm` does it from the command line.

### Security

See [decisions/0005](decisions/0005-secure-by-default.md). Routes are opt-in, strict mode is on, limits are enforced, private sources need signed URLs that expire, and metadata is stripped.

```php
// routes/web.php
Route::image('img')->middleware('cache.headers'); // GET /img/{signature}/{spec}/{resolver}/{key}.{format}
```

### Analysis and placeholders

`Image::analyze($reference)` returns the dimensions, the dominant color and a ThumbHash placeholder. A media library calls it at ingest and stores the result; variants and `<x-image>` use it when the source provides it.

### Events

`ImageRendered` (source, spec, duration, size), `ImageRenderFailed`, and `DerivativesCleared`. They are the hooks for metrics and logs.

### Testing

```php
Image::fake();

$this->get('/articles/1')->assertOk();

Image::assertUrlGenerated(preset: 'card');
Image::assertRendered(fn (ImageSpec $spec) => $spec->width === 800);
```

`Image::fake()` builds real URLs and variants from source metadata, renders nothing, and needs no image driver. It replaces the `Renderer` with `FakeRenderer`, and records the URLs the `UrlCodec` encodes.

Implementations of the contracts are tested with the traits of `Folklore\Image\Testing\Contracts` (`SourceResolverContractTests`, `RendererContractTests`, `DerivativeStoreContractTests`, `OperationContractTests`), which other packages use too: `laravel-mediatheque` 2 runs the resolver tests on its `media` resolver.

### Commands

| Command | Purpose |
|---|---|
| `image:doctor` | Driver in use, formats read and written, FFI and libvips, disks, lock store, signing key |
| `image:presets [--json]` | Presets and their versions |
| `image:warm {reference} [--preset=*]` | Render presets ahead of time |
| `image:clear {reference} [--stale]` | Delete a source's derivatives, or those of preset versions no longer configured |
| `image:upgrade [--check]` | Upgrade a 1.x project ([decisions/0007](decisions/0007-compatibility-layer.md)) |

## Configuration

```php
return [
    'renderer' => ['driver' => 'auto'],                 // auto | vips | imagick | gd
    'resolvers' => ['disk' => ['disk' => 'public']],    // the mediatheque adds 'media'
    'presets' => [/* … */],
    'quality' => ['jpeg' => 82, 'webp' => 80, 'avif' => 60],
    'url' => ['host' => env('IMAGE_URL'), 'prefix' => 'img'],
    'signing' => ['keys' => [], 'default_ttl' => 3600],  // empty: derived from APP_KEY
    'strict' => true,
    'limits' => ['max_width' => 5000, 'max_height' => 5000, 'max_pixels' => 25_000_000, 'max_operations' => 10],
    'derivatives' => ['store' => 'disk', 'disk' => 'public', 'path' => 'derivatives'],
    'delivery' => ['serve' => 'stream', 'max_age' => 31536000],
    'upscale' => false,
    'memory_limit' => '256M',
];
```

A published config only needs the keys it changes, as in 1.1.

## Front ends

There is no JavaScript package in 2.x ([decisions/0008](decisions/0008-no-javascript-package.md)). The server builds every URL, and front ends render the `ImageVariant` JSON (or a `srcset` string) they receive with a media or a page.

## Code layout

```
src/
  Facades/Image.php
  ImageManager.php             entry point behind the facade
  PendingImage.php             fluent, immutable builder
  Contracts/                   SourceResolver, Renderer, Operation, DerivativeStore, UrlCodec, Signer, Preset
  Exceptions/                  InvalidImageSpec, InvalidImageUrl, SourceNotFound, …
  Sources/                     ImageSource, DiskResolver
  Specs/                       ImageSpec, Transform, Crop, Fit, Flip, Format, Focus, Ratio, Size, ArrayPreset, PresetRegistry
  Urls/                        ImageUrl, PresetReference, V2Codec, V1Codec, HmacSigner
  Rendering/                   RenderedImage, InterventionRenderer, Operations/
  Storage/                     DerivativeKey, StoredDerivative, DiskStore, PublicPathStore, NullStore
  Http/                        ImageController, LegacyImageController, routes macros
  Variants/                    ImageVariant, ImageVariantResource, Srcset
  View/Components/Image.php    <x-image>
  Testing/                     ImageFake, FakeRenderer, Contracts/ (contract test traits)
  Console/                     doctor, presets, warm, clear, upgrade
  Compat/                      1.x facade methods, helpers, config reader
tests/fixtures/urls/           codec fixtures (JSON): v2.json, and every 1.x URL format in v1.json
tests/fixtures/                specs.json, signatures.json, images/
```

Namespace: `Folklore\Image`, PSR-4 from `src/`. The container binds `Folklore\Image\ImageManager` and the contracts; the generic `image` key is only bound by the compatibility layer.

## Dependencies

- Required: `illuminate/*` components the code uses, `intervention/image` ^4.
- Suggested: `intervention/image-driver-vips` (libvips), `ext-imagick`, `league/flysystem-aws-s3-v3` (S3-compatible disks), `folklore/laravel-mediatheque` (the `media` resolver).
- Removed: `imagine/imagine`, `contao/imagine-svg`, `guzzlehttp/guzzle`.

## Supported versions

PHP 8.3+ and Laravel 12+ ([decisions/0001](decisions/0001-supported-versions.md)).
