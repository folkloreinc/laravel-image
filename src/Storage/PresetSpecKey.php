<?php

declare(strict_types=1);

namespace Folklore\Image\Storage;

use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Urls\PresetReference;

/**
 * Where a store records the spec a preset had at one of its versions, for a version of a
 * source, when a derivative is rendered from it. A URL of that preset version finds its
 * stored derivatives through it once the preset has changed
 * (docs/decisions/0012-earlier-preset-versions.md).
 *
 *     {resolver}/{source key}/{source version or _}/presets/{preset}.{preset version}.json
 *     media/med_01JAB3QX/8c1f/presets/card.3f9a1c.json
 *
 * It is in the directory of the source's derivatives, so clearing a source clears it too.
 */
final readonly class PresetSpecKey
{
    public function __construct(
        public string $resolver,
        public string $sourceKey,
        public ?string $sourceVersion,
        public PresetReference $preset,
    ) {}

    public static function for(ImageSource $source, PresetReference $preset): self
    {
        return new self($source->resolver, $source->key, $source->version, $preset);
    }

    public function path(): string
    {
        return "{$this->resolver}/{$this->sourceKey}/".($this->sourceVersion ?? '_')."/presets/{$this->preset->toString()}.json";
    }
}
