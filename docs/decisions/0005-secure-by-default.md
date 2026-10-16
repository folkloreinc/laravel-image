# 0005: Secure by default: opt-in routes, strict mode, signed URLs

- Status: accepted
- Date: 2026-10-16

## Context

In 1.x:

- a catch-all image route is registered automatically, unless the project sets `routes.map` to `null`;
- any URL can ask for any size and any filter. 1.1 added restrictions and limits, but in `log` mode by default, so existing sites keep working;
- every image is public: there is no way to serve a private image through a short-lived URL.

Rendering is expensive. A route that renders any request is an easy way to fill a disk or exhaust a server. Niche keeps the 1.x route disabled for this reason, and needs private images for reserved content.

## Decision

- **Routes are opt-in.** Nothing is registered until the project calls `Route::image()` (or `Image::routes()`), with its own middleware.
- **Strict mode is on by default.** A request is rendered only if it is:
  - a **preset** at its current version (or a version still stored), or
  - a **signed URL**, whatever its spec.

  Free specs in unsigned URLs need `image.strict = false`, and stay within `image.limits` (sizes, pixels, operations), which are enforced, not logged.
- **Signed URLs** use HMAC-SHA256 over the whole path, with a key from `image.signing.keys` (the first one signs, all of them verify, so keys can rotate). The default key is derived from `APP_KEY`. A signature can carry an expiry.
- **Private sources** (a private media) are only served through signed URLs with an expiry. The resolver says whether a source is private ([0006](0006-sources-and-resolvers.md)); deciding who gets a URL stays the application's policy.
- **What a URL can never set:** memory limit, upscaling policy, metadata stripping, the driver. They come from the config or the route.
- **Metadata** (EXIF, GPS, comments) is stripped and colors converted to sRGB on every derivative, as in 1.1.

## Consequences

- A fresh install exposes no image route.
- Templates and APIs build URLs on the server, where they can be signed. 2.x has no JavaScript URL generator ([0008](0008-no-javascript-package.md)).
- The 1.x catch-all route is only registered by the legacy route, explicitly, during an upgrade ([0007](0007-compatibility-layer.md)).
