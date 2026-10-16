# 0004: Canonical image specs, versioned presets and a v2 URL format

- Status: accepted
- Date: 2026-10-16

## Context

In 1.x, a URL is a template (`{dirname}/{basename}{filters}.{extension}{format_extension}`) filled with filters in the order they were given. As a result:

- the same image can have several URLs (`300x300-crop-grayscale` and `grayscale-300x300-crop`), rendered and cached once per spelling;
- a named size (`-filters(large)`) keeps its URL when its definition changes, so CDNs and browsers keep serving the old rendering;
- a replaced original keeps its URLs too;
- the URL exposes the storage path;
- the template is configurable, so the route pattern, the parser and the JS generator must all agree on it.

1.x URLs live in CDN caches, sent emails and stored HTML. They must keep working.

## Decision

- **`ImageSpec`** is an immutable value object that describes one rendering: the source transform (crop, rotation, flip), the resize (width, height, fit, ratio, focal point, density, upscaling), the operations, the output format and quality. It is **canonical**: options are normalized, defaults removed and operations ordered, so two equal specs have the same `key()`.
- **Presets** are named specs. Each preset has a **version**, a short hash of its definition, which is part of its URLs. Changing a preset changes its URLs; old URLs keep rendering the old definition only as long as it is stored, never silently the new one.
- **Sources have a version** too (a file hash, a modification time or the media's own version), included in the URL, so a replaced original gets new URLs.
- **The v2 URL format** is fixed, not a template. It puts the signature first and the source last, since a source key can contain slashes:

  ```
  /{prefix}/{signature}/{spec}/{source}.{format}

  /img/_/card.3f9a,v-8c1f/med_01JAB3QX….webp                                     preset, unsigned
  /img/Hk2x…/w800,h450,fit-cover,fp-30-60,v-8c1f,exp-1767225600/uploads/2026/photo.jpg.avif   signed, expires
  ```

  `{spec}` is a preset and its version (`card.3f9a`) or canonical options, followed by the source version (`v-…`) and, for signed URLs, the expiry (`exp-…`). `{signature}` is `_` for unsigned URLs.

  The exact syntax is settled by the `UrlCodec` contract tests in phase 0, before alpha 1, and shared with the JS package as JSON fixtures.
- **`UrlCodec`** is a contract with two implementations: `V2Codec` and `V1Codec`. `V1Codec` decodes every URL 1.x generates, with the project's 1.x `image.url` config, **for as long as 2.x and later versions exist**. It is used by the legacy route ([0007](0007-compatibility-layer.md)).
- The prefix, the host and the route are configurable. The format itself is not.

## Consequences

- A variant is rendered and stored once, whatever the order of options in the code.
- Changing a preset or replacing an original never serves a stale image from a cache, and never needs a CDN purge.
- Variants of a deleted preset version stop rendering once their derivatives are cleared. `image:clear --stale` removes derivatives of versions no longer in the config.
- Projects that customized the 1.x URL template keep it for their 1.x URLs only.
