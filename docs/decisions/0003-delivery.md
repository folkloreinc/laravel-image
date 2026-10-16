# 0003: Laravel as the origin behind a CDN, derivatives on a disk

- Status: accepted
- Date: 2026-10-16

## Context

1.x renders an image on the first request and, with the route cache on, writes it under `public/` at the requested path. From then on, the web server serves it as a static file without booting Laravel. This works well on a single server, but:

- derivatives are lost on deploys that replace `public/`, and are not shared between servers;
- originals on S3-compatible storage are downloaded to each server;
- there is no lock: two requests for the same new variant both render it.

Content platforms such as Niche keep originals on S3-compatible storage, run several application servers, and put a CDN in front. Three delivery models were considered:

1. **Laravel as the origin behind a pull CDN** (Cloudflare, Bunny, CloudFront…): the CDN asks Laravel for a variant it doesn't have, Laravel renders it once and keeps it on a disk.
2. **Pre-generation at upload**, served directly from the bucket: only works for variants known in advance, and a CDN that can only pull from a bucket can't render anything new.
3. **An external image proxy** (imgproxy, Cloudflare Images…): the package would only build and sign URLs.

## Decision

- 2.x is built for **model 1**: Laravel renders on a miss, behind a CDN or a web server cache.
- **Derivatives are stored on any Laravel disk** through a `DerivativeStore`: the S3-compatible disk of the originals, a local disk, or the public path (the 1.x behaviour, served statically by the web server). A derivative's key comes from the canonical spec ([0004](0004-specs-and-urls.md)), so the same variant is never stored twice.
- **One render per variant:** rendering takes an atomic lock on the derivative key. Requests that wait for the lock read the stored derivative.
- **Serving a stored derivative:** stream it (the default), or redirect to the disk's URL when the disk is public (`image.delivery.serve = 'redirect'`).
- **Caching headers:** versioned URLs get `Cache-Control: public, max-age=31536000, immutable`, an `ETag` from the spec and `304` answers. URLs that expire get a `max-age` no longer than their remaining lifetime.
- **Pre-generation** stays possible as an addition, not a requirement: `Image::warm()` and `image:warm` render presets ahead of time in a queue, for example after an upload.
- **External proxies** come later as delivery drivers that encode URLs for the proxy (2.x). The `UrlCodec` and the presets are designed so they can.

## Consequences

- The image host needs a pull CDN (or a web server cache) in front of Laravel. With a CDN that can only pull from a bucket, only pre-generated presets can be served.
- The derivative disk and the lock store must be shared by all application servers (Redis or the database for locks).
- `image:clear` deletes the derivatives of a source, so a replaced original stops being served. Versioned URLs make this rarely necessary: a new original gives new URLs.
