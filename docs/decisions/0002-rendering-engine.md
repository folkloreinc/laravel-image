# 0002: Intervention Image 4, with libvips when available

- Status: accepted
- Date: 2026-10-16

## Context

1.x is built on [Imagine](https://github.com/php-imagine/Imagine), with the GD, Imagick and Gmagick drivers. Imagine is maintained, but:

- its API is untyped and its drivers behave differently on the same operation (1.x has workarounds for orientation, profiles and HEIC);
- it has no maintained libvips driver. libvips streams images instead of decoding them whole, so it handles very large phone photos with a fraction of the memory and time that Imagick needs. Content platforms receive such photos every day;
- animated GIFs are not handled.

[Intervention Image](https://image.intervention.io) 4 (October 2026: 4.3) is typed, requires PHP 8.3, handles animations, and has official GD, Imagick and libvips drivers (`intervention/image-driver-vips`, through `jcupitt/vips` and FFI). It is the image library most Laravel developers already know.

Keeping two engines behind one contract was considered: it would keep 1.x custom filters working, but double the test matrix and the drivers to keep in sync, for filters that no known project writes (the project generator only registers sizes, as arrays of options).

## Decision

- 2.x renders with **Intervention Image 4**. `imagine/imagine` and `contao/imagine-svg` are removed.
- **Driver:** `image.renderer.driver` is `auto` by default: `vips` when `intervention/image-driver-vips` is installed and libvips loads, otherwise `imagick`, otherwise `gd`. `image:doctor` reports the driver in use and the formats it reads and writes.
- **Operations** are classes implementing an `Operation` contract that receives the Intervention image. Built-in operations and project operations use the same contract. Their names and options are the package's own vocabulary, not Intervention's, so the engine can change again without changing URLs or presets.
- The `Renderer` contract stays the only place that knows the engine. A project can bind its own renderer.
- SVG sources are served as they are by default; rasterizing them needs a driver that reads SVG (Imagick or libvips with librsvg).

## Consequences

- The CI matrix covers the three drivers. The vips job installs libvips and enables FFI.
- **Operations:** libvips needs `ffi.enable=true` for PHP-FPM (the default, `preload`, only allows FFI in preloaded code). The operations guide documents it, and `image:doctor` detects it.
- **Breaking change:** 1.x custom filters written against Imagine (closures and `Filter` classes) must be rewritten as operations. Array filters (named sizes) keep working through presets ([0007](0007-compatibility-layer.md)). `Image::make()` returns an Intervention image instead of an Imagine image.
- Output can differ slightly from 1.x for the same request (resampling, encoders). URLs don't change; cached derivatives are not regenerated unless cleared.
