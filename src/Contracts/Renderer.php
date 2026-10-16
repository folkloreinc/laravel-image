<?php

declare(strict_types=1);

namespace Folklore\Image\Contracts;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;

/**
 * Renders a source with a spec (docs/decisions/0002-rendering-engine.md). The renderer is the
 * only place that knows the image engine; the default one uses Intervention Image 4.
 *
 * A renderer always turns the image upright, converts it to sRGB and strips its metadata. It
 * applies the source's transform and focal point when the spec has none, resolves an auto
 * format from the source, and takes the upscaling policy and the quality per format from the
 * config. The output has the size of ImageSpec::outputSize().
 *
 * Implementations are tested with Folklore\Image\Testing\Contracts\RendererContractTests.
 */
interface Renderer
{
    /**
     * @throws SourceNotFound when the original can't be read
     * @throws RenderFailed when the driver can't decode the source or encode the format
     * @throws InvalidImageSpec when the spec doesn't apply to the source, such as a crop outside it
     */
    public function render(ImageSource $source, ImageSpec $spec): RenderedImage;
}
