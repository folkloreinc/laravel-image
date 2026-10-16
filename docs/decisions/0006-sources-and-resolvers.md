# 0006: Sources are resolved, and carry their metadata

- Status: accepted
- Date: 2026-10-16

## Context

In 1.x, a source is a place (a local directory or a Laravel disk) and a URL contains the file's path in it. As a result:

- URLs expose storage paths, and break when a file moves;
- a media library can't serve images by id, nor keep some of them private;
- to give the `width` and `height` of a variant (for `srcset` and layout), a consumer must read the original's dimensions itself. `laravel-folklore` reimplements this in its `ImageSize` entity.

`laravel-mediatheque` 2 stores media with an opaque `uid` (`med_…`), probes their dimensions, keeps a per-media visibility, and [provides the image resolver](https://github.com/folkloreinc/laravel-mediatheque/blob/v2.x/docs/decisions/0009-package-boundaries.md) for `laravel-image` 2. Panneau 5 stores crops, rotations and focal points as a non-destructive `transform` on the media or on the field value.

## Decision

- An image is built **from a reference**: a path (`'uploads/photo.jpg'`), a path on a named disk, a `media://med_…` reference, a media model, or anything a registered resolver accepts.
- A **`SourceResolver`** turns a reference into an **`ImageSource`**, and the key found in a URL back into the same source:

  ```php
  interface SourceResolver
  {
      public function name(): string;                          // "disk", "media"
      public function supports(mixed $reference): bool;
      public function resolve(mixed $reference): ImageSource;  // from code
      public function resolveKey(string $key): ImageSource;    // from a URL
  }
  ```

- **`ImageSource`** carries everything rendering and URL building need, without opening the file:
  - `key()`, the opaque part of the URL (`med_01JAB3…`, or the path for a disk source);
  - `version()`, which changes when the original changes ([0004](0004-specs-and-urls.md));
  - the disk and the path of the original, read only when rendering;
  - metadata when known: `width`, `height`, `mime`, `orientation`, `focalPoint`, `placeholder`;
  - `transform`, the default non-destructive edits (Panneau 5's shape: crop, rotate, flip, focal point);
  - `isPrivate()`.
- The package ships the **`disk` resolver** (any Laravel disk, with path containment as in 1.1). `laravel-mediatheque` 2 ships the **`media` resolver**. Applications can add their own.
- Missing metadata is read once from the original and cached, so variants of a source without metadata still get their dimensions.

## Consequences

- Image URLs built from media never expose storage paths, and follow the media's visibility.
- `ImageVariant` has exact `width` and `height` with no file I/O when the resolver knows the dimensions.
- The resolver contract is reviewed with `laravel-mediatheque` 2 in phase 0, before either alpha ships it.
- `Image::analyze()` computes what a media library stores at ingest (dimensions, dominant color, ThumbHash placeholder). The mediatheque calls it and stores the result; this package doesn't own a database.
