<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Rendering;

final class ImagickRendererTest extends InterventionRendererTestCase
{
    protected static function driver(): string
    {
        return 'imagick';
    }
}
