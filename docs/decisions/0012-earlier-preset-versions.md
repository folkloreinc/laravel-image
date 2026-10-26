# 0012: Earlier preset versions are served from a record of their spec

- Status: accepted
- Date: 2026-10-26

## Context

[0004](0004-specs-and-urls.md) says that old URLs of a preset "keep rendering the old definition only as long as it is stored", and [0005](0005-secure-by-default.md) that strict mode serves "a preset at its current version (or a version still stored)". 2.0.0-alpha.1 answers 404 as soon as a preset URL's version isn't the current one, so a page cached by a CDN or a browser shows broken images once a preset changes. The review of 2026-10-23 decided to follow the decisions ([#96](https://github.com/folkloreinc/laravel-image/issues/96)).

The store can't find these derivatives from the URL alone:

- a derivative is stored under the tokens of its spec ([0009](0009-v2-url-syntax.md), `DerivativeKey`), not under the preset's name and version;
- the preset registry only knows the current definition of each preset, so the spec of an earlier version can't be computed again. A preset class can also give each source its own spec.

## Decision

- **Rendering a preset records its spec.** When the route renders a derivative from a preset at its current version, the `DerivativeStore` records the preset's spec for that version and that version of the source (`putPresetSpec()`, at the path of `PresetSpecKey`):

  ```
  {resolver}/{source key}/{source version or _}/presets/{preset}.{preset version}.json
  media/med_01JAB3QX/8c1f02ab/presets/card.3f9a1c.json
  ```

  It is the spec the preset gives this source, before the URL's width and format, in the array form of `ImageSpec`. It sits with the source's derivatives, so clearing a source clears it.
- **A URL of an earlier version of a known preset** reads that record (`presetSpec()`), applies the URL's width and format as for the current version, and serves the derivative stored under the resulting spec.
- **Nothing is ever rendered for an earlier version.** Without a record, or without a stored derivative for the URL's width and format, the URL answers 404, as before. An unknown preset name answers 404 whatever its version.
- The widths of an earlier version aren't known any more, so its URLs aren't checked against them: only a derivative that was rendered, and so had a valid width then, can be served.

## Consequences

- A page cached with the URLs of an earlier preset version keeps its images, at no extra rendering, until the source's derivatives are cleared. Changing a preset no longer needs a deploy that clears cached pages.
- `DerivativeStore` has two more methods, `putPresetSpec()` and `presetSpec()`, tested by `DerivativeStoreContractTests`. It is a breaking change for stores written for 2.0.0-alpha.1.
- Each preset version costs one small file per source version on the derivatives store, written when its first derivative is rendered.
- Derivatives rendered by 2.0.0-alpha.1 have no record, so their earlier preset versions still answer 404 once the preset changes.
- `image:clear --stale` (planned) can find the derivatives of preset versions no longer configured through these records.
