<?php

declare(strict_types=1);

namespace Folklore\Image\Rendering;

use Folklore\Image\Contracts\Renderer;

/**
 * A renderer whose upscaling policy, from the config, can be replaced for one route: the legacy
 * route keeps 1.x's, where crops upscale and resizes don't (docs/decisions/0007-compatibility-layer.md).
 * A renderer without it renders every route with the config's policy.
 *
 * @internal
 */
interface UpscalingPolicy
{
    /**
     * The same renderer, with outputs larger than their source or not.
     */
    public function withUpscale(bool $upscale): Renderer;
}
