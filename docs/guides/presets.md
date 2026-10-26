# Presets

A preset is a named rendering: a size, a shape, a format. Templates and APIs ask for presets by name, and strict mode serves them without a signature.

## Defining presets

```php
// config/image.php
'presets' => [
    'card' => ['width' => 800, 'ratio' => '16:9', 'widths' => [400, 800, 1200], 'formats' => ['avif', 'webp']],
    'og' => ['width' => 1200, 'height' => 630, 'format' => 'jpeg'],
    'thumbnail' => ['width' => 200, 'height' => 200],
    'email' => ['width' => 600, 'format' => 'jpeg', 'quality' => 80],
],
```

The options of a preset:

| Option | Values |
|---|---|
| `width`, `height` | pixels |
| `ratio` | `'16:9'`: with a width or a height, gives the other one; alone, crops the source to it |
| `fit` | how the image fits a box of two dimensions: `cover` (the default: fills it and crops around the focal point), `contain` (fits inside and pads), `inside` (fits inside, no padding), `fill` (distorts) |
| `crop`, `rotate`, `flip` | edits applied to the upright original first, in this order: `['x' => …, 'y' => …, 'width' => …, 'height' => …]` in pixels, `90` (clockwise), `'horizontal'`. Without them, the source's own edits apply, such as a media's transform from Panneau 5 |
| `focus` | the point to keep when `cover` crops, `['x' => 0.3, 'y' => 0.6]` from 0 to 1, relative to the upright original even when the image is cropped or rotated; the source's focal point by default, otherwise the center |
| `format` | `auto` (the default: the source's format when browsers show it, otherwise JPEG), `jpeg`, `png`, `gif`, `webp`, `avif` |
| `quality` | 1 to 100; the config's quality for the format by default |
| `widths` | the responsive widths of the preset, for `variants()` and preset URLs |
| `formats` | the modern formats offered before the fallback, such as `['avif', 'webp']` |

Images are never larger than their original, unless `image.upscale` is on.

A preset that depends on the source is a class that implements `Folklore\Image\Contracts\Preset`, registered by its class name in the config, or in code with `Image::presets()->register(new MyPreset)`.

## Edits for one use

A media can have its own edits (its `transform`, from Panneau 5's editor), which every preset applies. A use of the media, such as the value of an `image` field, can have its own, in the same shape:

```php
Image::for($media)->transform($value['transform'])->preset('card')->signed(false)->url();
```

- **Without `base`** (or with `base: "original"`), the crop and the focal point are in pixels of, and relative to, the upright original, and the transform replaces the media's for this use.
- **With `base: "media"`**, they are relative to the media's version, and the transform applies over the media's: both make one transform, which changes when the media's changes.

A crop past the edges of the image is clamped to it, and a crop wholly outside leaves the whole image. Keys the renderer doesn't know, such as `baseTransform`, are ignored. The edits are part of the URL, so each use gets its own; it is a free spec, which strict mode only serves signed: `signed(false)` gives a URL that never expires, cached like a preset's ([decisions/0014](../decisions/0014-per-usage-transforms.md)).

## Versions

Each preset has a version: the first 6 hexadecimal characters of a hash of its definition, written in its URLs (`card.3f9a1c`). The order of the options and their defaults don't change it.

Changing a preset changes its version, so its URLs change: CDNs and browsers never serve the old rendering for the new definition, and nothing needs purging.

URLs of an earlier version keep being served from the derivatives already stored, so a page cached with them keeps its images. Nothing is rendered for an earlier version: a variant of it that was never rendered, or whose derivatives were cleared, answers 404 ([decisions/0012](../decisions/0012-earlier-preset-versions.md)). Derivatives rendered by 2.0.0-alpha.1 don't count: their earlier versions answer 404.

## Preset URLs

```php
Image::for($media)->preset('card')->url();                 // _/card.3f9a1c,v-8c1f02ab/…jpg
Image::for($media)->preset('card')->width(400)->url();     // _/card.3f9a1c,w400,v-8c1f02ab/…jpg
Image::for($media)->preset('card')->avif()->url();         // _/card.3f9a1c,v-8c1f02ab/…avif
```

A preset URL can only change two things: the width, to one of the preset's `widths`, and the format. A width keeps the preset's shape: `og` at a width of 600 is 600×315.

Any other change (another width, a quality, a ratio) makes a **free spec**: the URL lists every option instead of the preset's name. Strict mode only serves free specs signed, so `url()` refuses to build an unsigned one in strict mode; call `signed()` ([urls-and-signatures.md](urls-and-signatures.md)).

```php
Image::for($media)->preset('card')->quality(60)->signed()->url();
```

## Variants

A variant is a rendering with its URL, its exact dimensions and its type, computed from the source's metadata without opening the file:

```php
$variant = Image::for($media)->preset('card')->webp()->variant();
$variant->url;     // https://img.example.com/img/_/card.3f9a1c,v-8c1f02ab/media/med_01JAB3QX.webp
$variant->width;   // 800
$variant->height;  // 450
$variant->mime();  // image/webp
```

`variants()` gives every width of a preset in each of its formats, then in the fallback format (the preset's, or the source's), by format, then by width:

```php
Image::for($media)->variants('card');
// avif 400, 800, 1200; webp 400, 800, 1200; jpeg 400, 800, 1200
```

A format given with `format()` is the only one. A preset without widths gives its own width.

Variants need the source's dimensions. The `disk` and `media` resolvers give them; a source without dimensions throws `InvalidImageSource`.

## In APIs

`ImageVariant` serializes to JSON, and `ImageVariantResource` gives it the same shape in API resources:

```php
use Folklore\Image\Variants\ImageVariantResource;

return ImageVariantResource::collection(Image::for($media)->variants('card'));
```

```json
{ "preset": "card", "url": "https://img.example.com/img/_/card.3f9a1c,w400,v-8c1f02ab/media/med_01JAB3QX.webp", "width": 400, "height": 225, "mime": "image/webp" }
```

Front ends render these URLs as they are: there is no JavaScript URL builder in 2.x, so URLs can be signed and checked on the server.
