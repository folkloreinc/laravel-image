# 0001: PHP 8.3+ and Laravel 12+

- Status: accepted
- Date: 2026-10-16

## Context

The workspace rule: a new line can require more than the stack floor (PHP 8.2, Laravel 9) when all the active projects that will use it are more recent. 1.x keeps the floor, so projects on older versions are not left behind: they stay on `v1.x` until they upgrade Laravel.

- The pilot project, Niche, runs PHP 8.5 and Laravel 13.
- Panneau 5 and `laravel-mediatheque` 2, designed with the same pilot, require PHP 8.3+ and Laravel 12+. A project that moves to one of them moves to all of them, so the floors should match.
- The rendering engine chosen in [0002](0002-rendering-engine.md), Intervention Image 4, requires PHP 8.3.
- Laravel 12 gets security fixes until February 2027. PHP 8.3 gets them until the end of 2027.

## Decision

- `laravel-image` 2.x requires **PHP 8.3+** and **Laravel 12+**.
- CI tests PHP 8.3 to 8.5 against Laravel 12 and 13, with `prefer-lowest` on the lowest PHP version of each Laravel version, as on `v1.x`.
- Dropping Laravel 12 follows the workspace rule: in a new minor, once no active 2.x project uses it.
- `v1.x` keeps PHP 8.2+ and Laravel 9+, for fixes and security.

## Consequences

- The code can use PHP 8.3 features: typed class constants, readonly classes, enums, `#[\Override]`.
- Projects on Laravel 9 to 11 upgrade Laravel before they upgrade this package. The upgrade guide says so first.
