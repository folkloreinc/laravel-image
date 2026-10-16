<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Rendering;

final class GdRendererTest extends InterventionRendererTestCase
{
    protected static function driver(): string
    {
        return 'gd';
    }
}
