# 0011: `Image::routes()` is the 1.x route

- Status: accepted
- Date: 2026-10-26
- Amends: [0005](0005-secure-by-default.md)

## Context

[0005](0005-secure-by-default.md) says that nothing is served until a project calls `Route::image()` "(or `Image::routes()`)", and 2.0.0-alpha.1 made `Image::routes()` an alias of `Route::image()`: both registered the 2.x route.

In 1.x, `Image::routes($config)` registers the 1.x catch-all route, and [0007](0007-compatibility-layer.md) keeps it, deprecated, in the compatibility layer. With the alias, a 1.x project that updates to 2.x and calls `Image::routes()` would get the 2.x route without knowing it: its `-filters(...)` URLs, in stored HTML and sent emails, would answer 404.

The review of 2026-10-23 settled it ([#95](https://github.com/folkloreinc/laravel-image/issues/95)).

## Decision

- **The 2.x route is registered with `Route::image()` only.** Everything else 0005 says about it holds: it is opt-in, with the project's own middleware.
- **`Image::routes()` is kept for the 1.x route only.** With the compatibility layer, in the beta, it registers `Route::imageLegacy()`, deprecated, as [0007](0007-compatibility-layer.md) says.
- **Until then, `Image::routes()` throws** a `BadMethodCallException` whose message points to `Route::image()`. A project gets an error at boot, never a route that serves other URLs than the ones it has.

## Consequences

- A project on 2.0.0-alpha.1 that calls `Image::routes()` replaces it with `Route::image()`. It is a breaking change between alphas, listed in the changelog.
- A 1.x project that updates before the beta fails at boot instead of answering 404 to its 1.x URLs.
- The route of `Route::image()` lives in `Folklore\Image\Http\ImageRoute`, not on `ImageManager`, so the facade has no method that registers it.
