# 0015: The legacy route renders on demand, and the 1.x config stays where it was

- Status: proposed
- Date: 2026-10-29

## Context

[0007](0007-compatibility-layer.md) keeps 1.x URLs and the 1.x API working, with a legacy route "with the 1.x restrictions and the public path cache", and `image:upgrade`. It doesn't say where the 1.x settings live once the config is in the 2.x shape, how the legacy route stores what it renders, or when the `image` key of the container is bound. A first project, urbania.media, upgrades now, together with `laravel-mediatheque` 2, `laravel-folklore` 2 and Panneau 5 ([#107](https://github.com/folkloreinc/laravel-image/issues/107)). Its config uses its own 1.x template (`{dirname}/{filters}{basename}.{extension}`, `filters/{filter}/`), and its code reads `config('image.sizes')` and calls `$this->app['image']->filter()` at boot.

## Decision

- **The 1.x keys stay where 1.x had them.** The compatibility layer reads `url.format` (and the other keys of the template), `source`, `sources`, `restrictions`, `filters`, `sizes` and `routes` at their 1.x place, with 1.x's defaults. The template keys sit next to the 2.x `url.host` and `url.prefix`, which don't clash. `image:upgrade` keeps the ones a project changes, so the same config works before and after it.
- **A named filter is a 1.x filter, or else a preset.** `-filters(large)` expands the filter registered with `Image::filter()`, as 1.x did, or the preset of that name, so 1.x URLs keep working once sizes are presets.
- **The legacy route renders on demand, without the derivatives store.** With `cache`, it writes each image at its request path under `cache_path`, as 1.x did, and serves that file if it is there; without it, it renders each request. The 2.x store keys derivatives by spec, and 1.x upscaling would give the same spec another output, so the two never share a derivative.
- **1.x upscaling belongs to the route.** A route's `upscale` is null by default, and falls back to `image.upscale`, which is null by default too: crops upscale and resizes don't. The renderer takes another policy per route through an internal interface (`UpscalingPolicy`); a custom renderer without it renders with the config's policy. `image.upscale: null` is `false` on 2.x routes.
- **The `image` key is bound for 1.x configs.** It replaces Laravel 13's own `image` service, as 1.x did, so it is bound only when the config has 1.x keys, or when `image.bind_image` is true. `image:upgrade` writes `bind_image`, true when the code uses the key.
- **The routes file of `image.routes.map` is loaded at boot**, as in 1.x, without a deprecation: the upgraded config keeps it, and it registers `Route::imageLegacy()`.
- **What 2.x can't render answers 404**, as 1.x did for a filter it didn't have: rotations other than multiples of 90 degrees, filters written against Imagine, and, until their operations land, `blur`, `gamma`, `colorize` and `interlace`. `grayscale` and `negative` are built-in operations.

## Consequences

- A 1.x project upgrades with `composer require` and keeps its URLs, its routes file and its code; `image:upgrade` only moves its config to the 2.x shape. It refuses to lose a value (an unknown key, a value from `env()` or code) without `--force`, keeps the files it rewrites as `*.1x`, and leaves a routes file alone unless it only calls `$router->image()`.
- Named sizes are read as the boot code of 1.x projects registered them, whatever their `crop` key, so a preset read from `image.sizes` and the filter that code registers have the same version.
- Images of the legacy route aren't in the derivatives store: `image:clear` doesn't clear them, and they are only on the server that rendered them, as in 1.x.
- A 2.x project that doesn't use 1.x keys keeps Laravel's `image` service.
