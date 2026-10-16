# Decisions

One file per decision. A decision is not edited once accepted: a later one replaces it and links to it.

| # | Decision | Status |
|---|---|---|
| [0001](0001-supported-versions.md) | PHP 8.3+ and Laravel 12+ | Accepted |
| [0002](0002-rendering-engine.md) | Intervention Image 4, with libvips when available | Accepted |
| [0003](0003-delivery.md) | Laravel as the origin behind a CDN, derivatives on a disk | Accepted |
| [0004](0004-specs-and-urls.md) | Canonical image specs, versioned presets and a v2 URL format | Accepted |
| [0005](0005-secure-by-default.md) | Secure by default: opt-in routes, strict mode, signed URLs | Accepted |
| [0006](0006-sources-and-resolvers.md) | Sources are resolved, and carry their metadata | Accepted |
| [0007](0007-compatibility-layer.md) | A compatibility layer for 1.x until 3.0 | Accepted |
| [0008](0008-javascript-package.md) | A smaller TypeScript package; the server builds the URLs | Accepted |

Template:

```markdown
# NNNN: Title

- Status: proposed | accepted | replaced by NNNN
- Date: YYYY-MM-DD

## Context
## Decision
## Consequences
```
