<?php

declare(strict_types=1);

namespace Folklore\Image\Facades;

use Folklore\Image\ImageManager;
use Folklore\Image\Testing\ImageFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Folklore\Image\PendingImage for(mixed $reference)
 * @method static \Illuminate\Routing\Route routes(?string $prefix = null)
 * @method static void resolver(\Folklore\Image\Contracts\SourceResolver $resolver)
 * @method static void useHost(?string $host)
 * @method static \Folklore\Image\Specs\PresetRegistry presets()
 * @method static \Folklore\Image\Sources\SourceResolvers resolvers()
 * @method static list<\Folklore\Image\Urls\ImageUrl> generatedUrls(?string $preset = null, ?\Closure $callback = null)
 * @method static void assertUrlGenerated(?string $preset = null, ?\Closure $callback = null)
 * @method static void assertUrlNotGenerated(?string $preset = null, ?\Closure $callback = null)
 * @method static void assertRendered(?\Closure $callback = null)
 * @method static void assertNotRendered(?\Closure $callback = null)
 * @method static void assertNothingRendered()
 *
 * @see ImageManager
 * @see ImageFake
 */
final class Image extends Facade
{
    /**
     * Replaces the renderer with one that renders nothing and records what it is asked, and
     * records the URLs generated, for the assertions of ImageFake. URLs and variants stay real.
     */
    public static function fake(): ImageFake
    {
        $container = self::getFacadeApplication();
        if ($container === null) {
            throw new \RuntimeException('Image::fake() needs a Laravel application.');
        }

        $fake = new ImageFake($container);
        self::swap($fake);
        $container->instance(ImageManager::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return ImageManager::class;
    }
}
