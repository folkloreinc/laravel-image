# 0009: The v2 URL syntax

- Status: accepted
- Date: 2026-10-16

## Context

[0004](0004-specs-and-urls.md) fixed the shape of v2 URLs (`/{prefix}/{signature}/{spec}/{source}.{format}`) and left their exact syntax to the `UrlCodec` contract tests of phase 0. Writing them raised three questions 0004 doesn't answer:

- **Which resolver a key belongs to.** A URL key is opaque (`med_01JAB3…`, `uploads/photo.jpg`), and a project can register several resolvers. Guessing from the key, or from the order of the resolvers in the config, would make URLs depend on the config.
- **What a preset URL can add.** Responsive variants need a preset at several widths (`card.3f9a,w400`), but if a preset URL could carry any option, strict mode ([0005](0005-secure-by-default.md)) would render any size of any preset without a signature.
- **How strict decoding is.** 0004 wants one URL per variant: `w300,h200` and `h200,w300` must not be two cache entries.

## Decision

The path after the route prefix is:

```
{signature}/{spec}/{resolver}/{key}.{extension}

_/card.3f9a1c,v-8c1f/media/med_01JAB3QX.webp                                   a preset, unsigned
_/card.3f9a1c,w400,v-8c1f/media/med_01JAB3QX.avif                              a preset at a responsive width
6tasEwQ9sn9mUqQa56D_4A/w800,h450,fp-30-60,v-8c1f,exp-1767225600/disk/uploads/2026/photo.jpg.avif   a free spec, signed, that expires
```

- **`{signature}`** is `_` for unsigned URLs, or 22 base64url characters: HMAC-SHA256 of the rest of the path (the payload, after the signature segment), truncated to 128 bits. The first key of `image.signing.keys` signs, all of them verify. The prefix and the host aren't signed, so they can change without breaking signatures.
- **`{spec}`** is comma-separated tokens, or `_` when there are none:
  - either a **preset** at a version (`card.3f9a1c`, a name and the first 6 hex characters of a SHA-256 of its canonical definition), optionally followed by a width (`w400`) that must be one of the preset's widths;
  - or a **free spec**, in this order: `c-{x}-{y}-{width}-{height}` (crop, source pixels), `r-{90|180|270}`, `flip-{h|v|hv}`, `w{width}`, `h{height}`, `ar-{width}-{height}` (reduced), `fit-{contain|inside|fill}` (cover is the default), `fp-{x}-{y}` (focal point in percent, only when cropping), operations sorted by name (`blur-5`, `grayscale`, `duotone-ff0000-0000ff`), `q{quality}`;
  - then the source version (`v-…`) when the source has one, then the expiry (`exp-{unix time}`), only on signed URLs.
- **`{resolver}`** names the resolver of the key: `media`, `disk`, or a project's own. URLs never depend on the order of the resolvers in the config.
- **`{key}`** is the source key, each segment percent-encoded (RFC 3986: only `A–Z a–z 0–9 - _ . ~` stay as they are). Keys can't have empty, `.` or `..` segments, backslashes or control characters.
- **`{extension}`** is the output format: `jpg`, `png`, `gif`, `webp` or `avif`, one per format. It follows the key's own extension (`photo.jpg.avif`). The auto format is resolved from the source before a URL is built.
- **Operation names** are 2 to 32 lowercase letters, other than `ar`, `exp`, `fit`, `flip` and `fp`. Their arguments are lowercase letters and digits; decimals are scaled integers, defined by each operation.
- **One spelling per URL.** The decoder rebuilds the URL from what it read, encodes it again, and refuses the path unless both are identical: options out of order, defaults written out, options that have no effect (a fit or a focal point without a box), leading zeros, `.jpeg`, or a key encoded differently are all refused (404).
- **Derivatives** are stored under `{resolver}/{key}/{version or _}/{spec tokens or _}.{extension}`, with the same tokens.

The fixtures that settle this syntax are `tests/fixtures/urls/v2.json` (paths that decode and encode both ways, and paths that are refused), `tests/fixtures/signatures.json` (computed independently of the package) and `tests/fixtures/specs.json` (canonical specs and their keys).

## Consequences

- URLs are longer by the resolver segment (`media/`, `disk/`), and never ambiguous.
- A preset URL can only change the width, within the preset's widths, and the format. Anything else (a focal point, an operation) makes a free spec, which strict mode only renders when it is signed. A preset that needs another option per use is a preset class, or another preset.
- The URL format can't change once alpha 1 is tagged: a valid case of `v2.json` is never removed or changed, only added to.
- Decimal arguments are scaled integers (a gamma of 1.2 is `gamma-120`), so URLs need no escaping beyond the key.
