<?php

declare(strict_types=1);

namespace Folklore\Image\Testing;

use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Size;

/**
 * Renders nothing, and records what it is asked. Its output has empty contents, and the
 * dimensions a real renderer would give, from the source's metadata. A source without
 * dimensions is taken to have the size the spec asks for, or 1×1.
 */
final class FakeRenderer implements Renderer
{
    /** @var list<array{source: ImageSource, spec: ImageSpec}> */
    private array $rendered = [];

    public function __construct(
        private readonly bool $upscale = false,
    ) {}

    public function render(ImageSource $source, ImageSpec $spec): RenderedImage
    {
        $this->rendered[] = ['source' => $source, 'spec' => $spec];

        $sourceSize = $source->size() ?? new Size($spec->width ?? $spec->height ?? 1, $spec->height ?? $spec->width ?? 1);
        $size = $spec->forSource($source)->outputSize($sourceSize, $this->upscale);

        return new RenderedImage('', $spec->format->resolve($source->mime), $size->width, $size->height);
    }

    /**
     * @return list<array{source: ImageSource, spec: ImageSpec}>
     */
    public function rendered(): array
    {
        return $this->rendered;
    }
}
