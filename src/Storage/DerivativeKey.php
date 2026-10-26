<?php

declare(strict_types=1);

namespace Folklore\Image\Storage;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Urls\SpecTokens;

/**
 * Where a derivative is stored: one per source version and canonical spec, so the same
 * variant is never rendered twice (docs/decisions/0003-delivery.md).
 *
 *     {resolver}/{source key}/{source version or _}/{spec tokens or _}.{extension}
 *     media/med_01JAB3QX/8c1f/w800,h450.webp
 *
 * All the derivatives of a source are under directory(). Source keys are files, so a key is
 * never the directory of another source's key on a filesystem; on an object store, a source
 * `a` and a source `a/b` would share derivatives' directories.
 */
final readonly class DerivativeKey
{
    public function __construct(
        public string $resolver,
        public string $sourceKey,
        public ?string $sourceVersion,
        public ImageSpec $spec,
    ) {
        if ($spec->format === Format::Auto) {
            throw new InvalidImageSpec('A derivative has a concrete format: resolve the auto format from the source first.');
        }
    }

    /**
     * The key of a derivative of a source. The spec's format must be resolved first.
     */
    public static function for(ImageSource $source, ImageSpec $spec): self
    {
        return new self($source->resolver, $source->key, $source->urlVersion(), $spec->withFormat($spec->format->resolve($source->mime)));
    }

    public function path(): string
    {
        return $this->directory().'/'.($this->sourceVersion ?? '_').'/'.(implode(',', SpecTokens::encode($this->spec)) ?: '_').'.'.$this->format()->extension();
    }

    /**
     * The directory of every derivative of the source, all versions included.
     */
    public function directory(): string
    {
        return "{$this->resolver}/{$this->sourceKey}";
    }

    /**
     * The directory of every derivative of a source, all versions included.
     */
    public static function directoryOf(ImageSource $source): string
    {
        return "{$source->resolver}/{$source->key}";
    }

    public function format(): Format
    {
        return $this->spec->format;
    }
}
