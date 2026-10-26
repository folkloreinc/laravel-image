# 0013: The transform, the focal point and the metadata of a media

- Status: accepted
- Date: 2026-10-26

## Context

Alpha 2 renders the non-destructive edits and focal points that Panneau 5 stores on a media of `laravel-mediatheque` 2, so the three packages must read the same shape the same way. [0010](0010-media-resolver.md) left three points of the [media resolver review](https://github.com/folkloreinc/laravel-image/issues/59#issuecomment-6219674071) for alpha 2: the names of the metadata (3), where the focal point and the transform live (4), and clearing derivatives when a media changes (6). [#88](https://github.com/folkloreinc/laravel-image/issues/88) proposed answers; Panneau 5 accepted the shape (folkloreinc/panneau-js#80, folkloreinc/panneau-js#104), and the maintainer settled the open choices on 2026-10-26.

2.0.0-alpha.1 also had a bug: a source's transform wasn't part of its URLs, so editing a media kept URLs served as `immutable`, and CDNs and browsers kept the old rendering.

## Decision

### The transform is Panneau 5's `MediaTransform`

```php
[
    'crop' => ['x' => 0, 'y' => 120, 'width' => 1600, 'height' => 900],  // or null
    'rotate' => 90,                                                     // 0, 90, 180 or 270
    'flipX' => false,
    'flipY' => false,
    'focalPoint' => ['x' => 0.3, 'y' => 0.6],                           // or null
]
```

- The **crop** is in whole pixels of the upright original (after its EXIF orientation), from its top left corner: `x` and `y` from 0, `width` and `height` from 1. A crop past the edge of the original stops at the edge.
- The **rotation** is clockwise.
- **`flipX`** mirrors left to right, **`flipY`** top to bottom.
- Edits apply in this order: **crop, then rotate, then flip**.
- The **focal point** is from 0 to 1 on each axis, **relative to the upright original**, not to the crop, so changing the crop doesn't move it.
- Unknown keys are ignored.

`Transform::fromArray()` reads this shape, and still reads the spec form (`'flip' => 'horizontal' | 'vertical' | 'both'`), but not both flips at once. A `Transform` carries the focal point; `ImageSource` and `ImageSpec` keep it apart from the other edits, as the source's `focalPoint` and the spec's `focus`.

### Every focal point is relative to the upright original

The source's focal point and a spec's `focus` (`fp-…` in URLs) are both relative to the upright original. The renderer maps the focal point into the transformed image: it moves with the crop (a point outside the crop goes to its nearest edge), then turns with the rotation and the flip, before the cover crop around it. In 2.0.0-alpha.1, a spec's `focus` applied to the image after its transform; the two only differ for a spec that has both.

### The metadata of a media

- **`width` and `height`** are the displayed size, after the EXIF orientation (already the case on both sides).
- **`orientation`** is a canonical key: the EXIF orientation of the original, from 1 to 8. The mediatheque doesn't turn originals upright at ingest: this package turns every rendering upright, and `ImageSource::$orientation` carries the key.
- **The dominant color** is the first entry of `palette`. There is no separate key.
- **The placeholder** is `thumbhash` (mediatheque decision 0013).

### The transform is on the media, and enters the version of its URLs

- A media has one editorial **`transform`** field, outside `metadata`, in the shape above, focal point included. It is `Media.transform` in Panneau 5's protocol.
- The `media` resolver passes it as the source's transform: `transform: Transform::fromArray($media->transform)`. Its `version` stays the first 8 hex characters of the file's checksum ([0010](0010-media-resolver.md)).
- **This package hashes the edits into the version of URLs.** `ImageSource::urlVersion()` is the resolver's version, followed by the first 6 hex characters of a SHA-256 of the transform and the focal point when there are any: `v-8c1f02ab-3f9a1c`. URLs, `DerivativeKey` and the preset specs of [0012](0012-earlier-preset-versions.md) use it. Editing a media gives new URLs, and its derivatives are stored apart from those of its previous edits. A source without a version still has none.
- The edits go into the version, not only into `DerivativeKey`, because the version is what changes the URL: a new derivative under an unchanged URL would never reach a CDN that cached the old one as `immutable`.
- A spec's own transform wins over the source's, as before. The size of variants and the limits take the source's transform into account.
- A transform per usage (on a field value) is the application's, through the builder ([#90](https://github.com/folkloreinc/laravel-image/issues/90)).

### Clearing a media's derivatives

`Folklore\Image\Listeners\ClearMediaDerivatives` lives in this package. The service provider registers it when the mediatheque's events exist: on **`MediaVisibilityChanged`** and **`OriginalReplaced`** it clears the derivatives of the media, and those of its files (`med_…/{handle}`), from the derivative store. It runs synchronously, so a media made private keeps no derivative that a public disk could serve at its URL.

## Consequences

- Panneau 5 writes the transform, the mediatheque stores it and passes it through its resolver, and this package renders it: no package converts the shape of another.
- The mediatheque's side is folkloreinc/laravel-mediatheque#107: the `transform` field, the `orientation` key, and passing the transform to the `ImageSource`.
- Editing the crop, the rotation, the flips or the focal point of a media gives new URLs, and never needs a CDN purge. URLs of earlier edits are served while their derivatives are stored, as for an earlier version of the original.
- A URL's source version is longer when the source has edits (`v-8c1f02ab-3f9a1c`). The URL syntax doesn't change: versions already allow `-` ([0009](0009-v2-url-syntax.md)).
- Clearing derivatives doesn't purge a CDN. A public URL a CDN cached before the media was made private stays in its cache until it expires: a project that needs it gone sooner purges it, or keeps `image.delivery.max_age` short.
