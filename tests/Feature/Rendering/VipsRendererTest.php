<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Rendering;

final class VipsRendererTest extends InterventionRendererTestCase
{
    protected static function driver(): string
    {
        return 'vips';
    }
}
