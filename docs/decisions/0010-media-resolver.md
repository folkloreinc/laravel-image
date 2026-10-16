# 0010: The `media` resolver: versions, keys and disks

- Status: accepted
- Date: 2026-10-16

## Context

`laravel-mediatheque` 2 ships the `media` resolver ([0006](0006-sources-and-resolvers.md)). Its [review of the contract](https://github.com/folkloreinc/laravel-image/issues/59#issuecomment-6219674071) found three gaps that block alpha 1 on both sides:

1. **Version.** [0004](0004-specs-and-urls.md) puts the source version in every URL, and says that for a media it is "the media's own version". A media has none, and `replaceOriginal()` keeps its `uid`, so a replaced image would keep its URLs.
2. **Which file a key names.** The URL key is the media's `uid`, so it names a media, not a file. But video posters and thumbnails are files of their media, and must go through this package the same way as images.
3. **Disk.** `ImageSource` carries a Laravel disk and a path. A mediatheque file has a `source`, which in 1.x can be a local directory rather than a disk.

## Decision

- **The version of a media source is its file's content hash:** the first 8 hexadecimal characters of the SHA-256 `checksum` the mediatheque stores for every file. It changes exactly when the content changes, needs no new column, and survives copying a database. The `disk` resolver keeps its own version ([0006](0006-sources-and-resolvers.md)).
- **Keys name the media's image, or one of its files:**
  - `med_…` resolves to the media's image: the original for an `image` media, and its thumbnail (the poster first) for other types;
  - `med_…/{handle}` and `med_…/{handle}/{index}` resolve to a given file of the media: `med_01JAB3QX/poster`, `med_01JAB3QX/thumbnails/2`;
  - the version is always the resolved file's;
  - a media without an image file doesn't resolve (404).

  These keys need no change to the URL syntax ([0009](0009-v2-url-syntax.md)), which already allows slashes in a key:

  ```
  _/card.3f9a1c,v-8c1f02ab/media/med_01JAB3QX.webp
  _/card.3f9a1c,v-5e0d9a17/media/med_01JAB3QX/thumbnails/2.webp
  ```

- **The disk is the mediatheque file's source.** In 2.0, a mediatheque source is a Laravel disk name (1.x `local` sources become disks when a project upgrades), so the resolver passes `$file->source()` as the `disk` of the `ImageSource`, and the file's path as its `path`.

## Consequences

- Replacing a media's original, or regenerating a video's poster, gives new URLs. CDN caches never serve the old image, and nothing needs purging.
- A page can show a video's poster and its thumbnails through presets, with the same signatures, limits and caching as images.
- The mediatheque records its side (the `source` is a disk name, the resolved file per key) in its own docs (folkloreinc/laravel-mediatheque#32).
- The other points of the review are settled before the alpha that needs them:
  - metadata names (orientation, ThumbHash, dominant color), the focal point and the `transform`, and clearing derivatives when a media's visibility changes or its original is replaced: alpha 2;
  - how deleted, pending and failed media resolve, and which reference forms `supports()` accepts: in the mediatheque's resolver, before its alpha 1.
