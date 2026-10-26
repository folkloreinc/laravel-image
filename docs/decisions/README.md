# Decisions

One file per decision. A decision is not edited once accepted: a later one replaces it and links to it.

| # | Decision | Status |
|---|---|---|
| [0001](0001-supported-versions.md) | PHP 8.3+ and Laravel 12+ | Accepted |
| [0002](0002-rendering-engine.md) | Intervention Image 4, with libvips when available | Accepted. FFI is documented in the [getting started guide](../guides/getting-started.md), not an operations guide |
| [0003](0003-delivery.md) | Laravel as the origin behind a CDN, derivatives on a disk | Accepted |
| [0004](0004-specs-and-urls.md) | Canonical image specs, versioned presets and a v2 URL format | Accepted; the URL format is settled by 0009, and the versions of `media` sources by 0010 |
| [0005](0005-secure-by-default.md) | Secure by default: opt-in routes, strict mode, signed URLs | Accepted; amended by 0011 (`Image::routes()` is the 1.x route only); earlier preset versions are served from storage as it says, through 0012 |
| [0006](0006-sources-and-resolvers.md) | Sources are resolved, and carry their metadata | Accepted |
| [0007](0007-compatibility-layer.md) | A compatibility layer for 1.x until 3.0 | Accepted |
| [0008](0008-no-javascript-package.md) | No JavaScript package in 2.x; the server builds the URLs | Accepted |
| [0009](0009-v2-url-syntax.md) | The v2 URL syntax | Accepted |
| [0010](0010-media-resolver.md) | The `media` resolver: versions, keys and disks | Accepted; the resolver shipped in `laravel-mediatheque` 2.0.0-alpha.2, and the points left "for alpha 2" are settled by 0013 |
| [0011](0011-image-routes-is-the-1x-route.md) | `Image::routes()` is the 1.x route; the 2.x route is `Route::image()` | Accepted |
| [0012](0012-earlier-preset-versions.md) | Earlier preset versions are served from a record of their spec | Accepted |
| [0013](0013-transform-focal-point-and-metadata.md) | The transform, the focal point and the metadata of a media | Accepted |

Template:

```markdown
# NNNN: Title

- Status: proposed | accepted | replaced by NNNN
- Date: YYYY-MM-DD

## Context
## Decision
## Consequences
```
