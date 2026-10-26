# 0014: A transform for one use, over the media's own or from the original

- Status: accepted
- Date: 2026-10-26

## Context

[0013](0013-transform-focal-point-and-metadata.md) put one editorial `transform` on a media, which the `media` resolver passes as the source's transform, and left the transform of one use (the value of an `image` field) to the application. It didn't say how the two combine.

Panneau 5 decided it ([its decision 0031](https://github.com/folkloreinc/panneau-js/blob/v5.x/docs/decisions/0031-image-editor-and-image-field.md), protocol in folkloreinc/panneau-js#104). A transform for one use says what it starts from, with `base`:

- without `base`, or with `base: "original"`, it starts from the original file: its crop and focal point are in pixels of, and relative to, the upright original, as in 0013;
- with `base: "media"`, it applies over the media's own transform: its crop and focal point are in pixels of, and relative to, the media's version (the image after the media's crop, rotation and flips). It also keeps `baseTransform`, the media's transform it was made on, which Panneau compares with the current one to ask the user to check the crop.

When the media's transform changes, a crop made over the previous one can fall outside the new version. Panneau's protocol says renderers clamp it rather than fail ([#90](https://github.com/folkloreinc/laravel-image/issues/90)).

## Decision

- **`PendingImage::transform(array|Transform $transform, ?string $base = null)`** takes the transform of one use, in Panneau's shape. `base` is the array's `base` key by default; another value than `media` or `original` is refused. Other keys, `baseTransform` included, are ignored: the renderer doesn't need it.
- **Without `base`,** the transform replaces the source's own for this use. Without a crop, a rotation or a flip, it is the whole original, untouched.
- **With `base: "media"`,** it is composed with the source's transform into one transform of the upright original (`Transform::over()`):
  - its crop, in pixels of the media's version, maps back to a rectangle of the original;
  - quarter turns add up and flips combine, written as a rotation and at most one flip;
  - its focal point maps back to the original, so that every focal point stays relative to the upright original (0013); without one, the media's focal point applies;
  - over a source without a transform, it is the same as without `base`.
- **Composing happens in the builder,** before the spec's key and the URL are made. The renderers still apply one crop, one rotation and one flip. The composed edits are in the URL as a free spec (`c-…`, `r-…`, `flip-…`, `fp-…`), so each use has its own URL and derivative, and strict mode only serves it signed ([0009](0009-v2-url-syntax.md)). The URL's version still changes with the media's own edits (0013).
- **Clamping.** Everywhere a crop applies, the source of a spec included: a crop past the edges of the image is clamped to it, and a crop wholly outside leaves the whole image, instead of failing. The focal point is clamped to the crop, as in 0013.
- Composing needs the dimensions of the original when there is a crop or a focal point to map back over a media's edits. Without them, the builder throws.

## Consequences

- A field value made over a media's edits renders over them, and follows them when they change: a new media transform changes the composed one, and so the URL.
- A crop outside the image no longer answers 404: it renders the image clamped, or whole. `RendererContractTests` say so.
- Templates that show a field's image build signed URLs: `Image::for($media)->transform($value['transform'])->preset('card')->signed(false)->url()`. Without an expiry, they are cached like preset URLs.
- A crop clamped after the media's transform changed may not have the ratio Panneau's `crop` rule checked. Panneau asks the user to check the crop in this case.
