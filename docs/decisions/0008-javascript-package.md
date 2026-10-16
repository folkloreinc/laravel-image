# 0008: A smaller TypeScript package; the server builds the URLs

- Status: accepted
- Date: 2026-10-16

## Context

The npm package `laravel-image` mirrors the PHP URL generator, so a browser can build any image URL. 1.1 made both generators share JSON fixtures. But:

- no known project imports it;
- with strict mode ([0005](0005-secure-by-default.md)), only presets and signed URLs render, and a browser can't sign;
- front ends (Panneau 5, React applications) receive media as JSON from the server, with their variants already built.

## Decision

- The package keeps its name, `laravel-image`, and is rewritten in **TypeScript**, published as ESM with types.
- It no longer builds arbitrary URLs. It provides:
  - the types of `ImageVariant` and of the JSON the server returns;
  - `srcset()` and `sizes` helpers over a list of variants;
  - preset URLs for public sources, from a **preset manifest** exported by `php artisan image:presets --json`;
  - a decoder for v2 URLs (for tools and tests).
- PHP and TypeScript share the codec fixtures, so a preset URL is the same on both sides.
- No runtime dependencies.

## Consequences

- Front ends render what the server gives them: `variants` in the media JSON, or a `srcset` string.
- The 1.x JS generator stays on `v1.x` (npm `1.x`), for projects that use it.
