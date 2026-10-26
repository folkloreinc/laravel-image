# Delivery behind a CDN

2.x is built for Laravel as the origin behind a pull CDN ([decision 0003](../decisions/0003-delivery.md)): the CDN asks Laravel for a variant it doesn't have, Laravel renders it once, stores it on a disk and answers with headers that let the CDN keep it for a year.

```
browser ──▶ CDN (img.example.com) ──miss──▶ Laravel /img/… ──▶ stored derivative? ──yes──▶ stream / redirect
                                                                  │ no
                                                                  ▼
                                                     lock ─▶ render ─▶ store ─▶ stream
```

## What the origin answers

| URL | `Cache-Control` |
|---|---|
| A URL with a source version (`v-…`): presets and free specs | `public, max-age=31536000, immutable` |
| A URL that expires | `public, max-age={seconds left}, immutable` |
| A private source (always signed, always expiring) | `private, max-age={seconds left}, immutable` |
| A source without a version | `public, max-age=31536000` |

`max-age` comes from `image.delivery.max_age`. Every image response has an `ETag`, `Content-Type`, `Content-Length` and `X-Content-Type-Options: nosniff`, and answers `304` when the `ETag` matches. Error responses keep Laravel's `no-cache` headers, so a CDN doesn't keep a 404 for an image that appears later.

URLs change when a preset, an original or a media's edits change ([presets.md](presets.md#versions)), so nothing needs purging. Making a public media private is the exception: its public URLs stay in the CDN until they expire, unless you purge them.

## The route

Register the route outside the `web` middleware group ([getting-started.md](getting-started.md#register-the-route)): CDNs don't cache a response that sets a cookie, and one that did would hand a visitor's session cookie to the next visitors. `php artisan image:doctor` warns about a route with the `web` middleware.

With a dedicated image host, restrict the route to it:

```php
Route::image()->domain('img.example.com');
```

## Cloudflare

Point the image host (`img.example.com`) to the application, proxied (orange cloud), and set `IMAGE_URL=https://img.example.com`.

Then add a **cache rule** for the image prefix (Caching › Cache Rules):

- **When incoming requests match:** hostname equals `img.example.com` and URI path starts with `/img/`.
- **Cache eligibility:** Eligible for cache.
- **Edge TTL:** Use cache-control header if present, bypass cache if not. Cloudflare then keeps versioned URLs for a year, expiring ones no longer than they live, and never private ones.
- **Browser TTL:** Respect origin TTL.
- **Cache key:** ignore the query string. Image URLs have none, so a query string would only make a new cache entry for the same image.

Turn on **Tiered Cache**, so the edges ask a few upper tiers before the origin, and the origin renders and streams each variant fewer times.

Leave Cloudflare's own image optimizations (Polish, image transformations) off for this host: images are already resized and encoded.

The same settings apply to other pull CDNs (Bunny, CloudFront, Fastly): cache the image prefix, respect the origin's `Cache-Control`, ignore query strings, don't cache responses that set cookies.

## Stream or redirect

```php
'delivery' => ['serve' => 'stream', 'max_age' => 31536000],
```

- `stream` (the default): Laravel streams the derivative from its disk. The CDN then keeps it; Laravel only streams it again on a CDN miss.
- `redirect`: Laravel answers `302` to the derivative's URL on its disk, when the disk is public and gives one. It saves the origin's bandwidth, but the derivative's disk URL becomes public. Private sources are always streamed.

## Several servers

- **Derivatives** go on a disk every server reads: the S3-compatible disk of the originals, for example, under `image.derivatives.path`.
- **Render locks** go in a cache store every server shares, such as Redis or the database (`image.derivatives.lock.store`). Requests for the same new variant then wait for one render and read what it stored. `image:doctor` warns about a store that isn't shared.
