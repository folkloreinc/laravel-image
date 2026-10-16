# 0008: No JavaScript package in 2.x; the server builds the URLs

- Status: accepted
- Date: 2026-10-16

## Context

The npm package `laravel-image` mirrors the PHP URL generator, so a browser can build any image URL. 1.1 made both generators share JSON fixtures, but its JS changes were never published: npm's latest version is still 1.0.3 (2022). In October 2026:

- no active project depends on it. The only two projects that declare it are frozen, run Laravel 5.7 with the legacy `folklore/image` package, and use 1.0.3's React component;
- with strict mode ([0005](0005-secure-by-default.md)), only presets and signed URLs render, and a browser can't sign;
- front ends (Panneau 5, React applications) receive media as JSON from the server, with their variants already built.

Keeping a second implementation of the codec in sync, with its own tooling, build and release, costs more than it gives.

## Decision

- 2.x has **no JavaScript package**. The `js/` directory and the JS tooling are removed from `v2.x`.
- **The server builds every URL.** Front ends render what it returns:
  - `ImageVariant` and `ImageVariantResource` in the JSON of a media or a page (`url`, `width`, `height`, `mime`), and Panneau 5's `variants` map;
  - a ready `srcset` string, when a front end only needs that.
- The 1.x generator stays on `v1.x`. The published npm versions (1.0.x) stay as they are, for the projects that pin them.
- If a front end later needs to build URLs itself, a package can come back in a 2.x minor, generated from the PHP codec fixtures. Unsigned preset URLs of public sources are the only URLs it could build.

## Consequences

- One codec to test and maintain. The codec fixtures stay in JSON, for the PHP tests and any future client.
- `image:presets --json` stays, for tooling and documentation, not for a client package.
- Releases are tagged on GitHub and Packagist only.
