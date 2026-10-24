# URLs and signatures

Image URLs are built on the server, in one spelling only, and signed when they need to be. This guide covers what a URL says, how it is signed, and what the route refuses.

## The URL syntax

```
https://img.example.com/img/{signature}/{spec}/{resolver}/{key}.{extension}

https://img.example.com/img/_/card.3f9a1c,v-8c1f02ab/media/med_01JAB3QX.webp
https://img.example.com/img/_/card.3f9a1c,w400,v-8c1f02ab/media/med_01JAB3QX.avif
https://img.example.com/img/6tasEwQ9sn9mUqQa56D_4A/w800,h450,v-5e0d9a17,exp-1767225600/disk/uploads/2026/photo.jpg.avif
```

- `{signature}`: `_` for unsigned URLs, or 22 characters of HMAC-SHA256 over the rest of the path.
- `{spec}`: a preset at its version, optionally at one of its widths, or the options of a free spec in a fixed order; then the source's version (`v-…`) and, for URLs that expire, the expiry (`exp-…`, a Unix time).
- `{resolver}` and `{key}`: the source, such as `media/med_01JAB3QX` or `disk/uploads/2026/photo.jpg`. Each segment of the key is percent-encoded.
- `{extension}`: the output format (`jpg`, `png`, `gif`, `webp`, `avif`).

The exact syntax is [decision 0009](../decisions/0009-v2-url-syntax.md). A path in any other spelling (options in another order, a default written out, `.jpeg`) answers 404, so one image has one URL and one cache entry.

The source's version changes when the original changes, so a replaced image gets new URLs: caches never need purging.

## Host and prefix

```php
// config/image.php
'url' => [
    'host' => env('IMAGE_URL'),   // https://img.example.com; null for the application's URL
    'prefix' => 'img',
],
```

A site with several domains changes the host per request or per URL:

```php
Image::useHost('https://img.other-site.com');                       // the URLs of this request
Image::for($media)->preset('card')->host('https://img.other-site.com')->url(); // one URL
```

`useHost()` lasts until it is called again: with Octane, call it in a middleware on every request.

The prefix and the host aren't signed, so they can change without breaking signatures.

## Signed URLs

```php
Image::for($media)->width(1000)->ratio('4:5')->signed()->url();          // expires after the default TTL
Image::for($media)->preset('og')->signed(now()->addDay())->url();        // a time
Image::for($media)->preset('og')->signed(600)->url();                    // seconds
Image::for($media)->width(1000)->signed(expires: false)->url();          // never expires
```

Without an expiry, a signed URL lasts `image.signing.default_ttl` seconds (3600). URLs that expire change with their expiry: to keep the same URL for a while, so browsers and CDNs cache it, round the expiry, such as `signed(now()->addHour()->ceilHour())`.

Signatures use `image.signing.keys`. The first key signs and every key verifies, so a key rotates by putting the new one first and keeping the old one until its URLs are gone. Without keys, they are derived from `APP_KEY` and `APP_PREVIOUS_KEYS`: rotating the application key the Laravel way rotates them too, and signatures never reveal `APP_KEY`.

```env
IMAGE_SIGNING_KEYS=new-key,old-key
```

## Private sources

A resolver says whether a source is private (a private media of `laravel-mediatheque`). Private sources only get signed URLs that expire: `url()` signs them with the default TTL, and `signed(expires: false)` throws. Deciding who gets a URL stays the application's job: build the URL only for users who may see the image.

Their responses are `Cache-Control: private`, so a CDN doesn't keep them, and they are never served by a redirect to a public disk URL.

## Strict mode

Strict mode (`image.strict`, on by default) serves:

- presets at their current version, at one of their widths;
- signed URLs, whatever their spec.

In strict mode, `url()` throws instead of building an unsigned free spec, which the route would refuse. Without strict mode, unsigned URLs can ask for any spec within the limits; use it only for development, or when every page that builds URLs is trusted.

## Limits

```php
'limits' => ['max_width' => 5000, 'max_height' => 5000, 'max_pixels' => 25_000_000, 'max_operations' => 10],
```

Limits apply to every URL, signed or not, on the requested size and on the size of the output: they protect the server, whoever built the URL. `url()` throws for a URL over the limits.

## What the route answers

| Request | Answer |
|---|---|
| A canonical URL the route serves | `200`, or `304` when the `ETag` matches |
| A path in another spelling, an unknown preset or preset version, a width that isn't one of the preset's | `404` |
| An unsigned free spec in strict mode, a URL over the limits | `404` |
| An unknown resolver or key, an old version of the source that isn't stored any more | `404` |
| A private source without a signed URL that expires | `404` |
| An invalid signature, an expired URL | `403` |
| A source the driver can't read | `415` |
| A variant another request is rendering, after waiting `image.derivatives.lock.wait` seconds | `503`, with `Retry-After` |
