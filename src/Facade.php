<?php

declare(strict_types=1);

namespace Folklore\Image;

use Illuminate\Support\Facades\Facade as BaseFacade;

/**
 * The 1.x facade, which 1.x projects alias as `Image` in config/app.php. It is the same as
 * Folklore\Image\Facades\Image.
 *
 * @deprecated Use Folklore\Image\Facades\Image.
 * @see Facades\Image
 */
final class Facade extends BaseFacade
{
    protected static function getFacadeAccessor(): string
    {
        return ImageManager::class;
    }
}
