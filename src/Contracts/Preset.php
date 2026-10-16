<?php

declare(strict_types=1);

namespace Folklore\Image\Contracts;

use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;

/**
 * A named spec, with optional responsive widths and formats (docs/decisions/0004-specs-and-urls.md).
 * Presets from the config are ArrayPreset; a class implements this contract for a preset that
 * depends on the source.
 */
interface Preset
{
    /**
     * The name in code and URLs: lowercase letters, digits, - and _.
     */
    public function name(): string;

    /**
     * A short hash of the definition, part of the preset's URLs: changing the definition
     * changes the URLs, so caches never serve the old rendering for the new one. A class
     * preset changes it when its logic changes.
     */
    public function version(): string;

    /**
     * The spec for a source. Its format can be auto, resolved from the source.
     */
    public function spec(ImageSource $source): ImageSpec;

    /**
     * The responsive widths, in increasing order, or an empty list for the spec's size only.
     *
     * @return list<positive-int>
     */
    public function widths(): array;

    /**
     * The modern formats offered before the fallback in responsive markup (AVIF, WebP), or an
     * empty list for the spec's format only.
     *
     * @return list<Format>
     */
    public function formats(): array;
}
