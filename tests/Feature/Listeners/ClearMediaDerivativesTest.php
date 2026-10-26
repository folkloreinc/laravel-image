<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Listeners;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Listeners\ClearMediaDerivatives;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Testing\InMemoryDerivativeStore;
use Folklore\Image\Tests\Doubles\MediathequeEvent;
use Folklore\Image\Tests\TestCase;
use Illuminate\Support\Facades\Event;

/**
 * Without laravel-mediatheque 2, which this package doesn't require.
 */
final class ClearMediaDerivativesTest extends TestCase
{
    public function test_nothing_listens_without_the_mediatheque(): void
    {
        $this->assertSame([], ClearMediaDerivatives::installedEvents());
        foreach (ClearMediaDerivatives::EVENTS as $event) {
            $this->assertFalse(Event::hasListeners($event), $event);
        }
    }

    public function test_it_clears_the_derivatives_of_the_media_and_its_files(): void
    {
        $store = new InMemoryDerivativeStore;
        $keys = self::storeDerivatives($store);

        (new ClearMediaDerivatives($store))->handle(MediathequeEvent::forUid('med_01JAB3QX'));

        $this->assertSame([$keys['other']->path()], $store->paths());
    }

    public function test_an_event_without_a_media_uid_clears_nothing(): void
    {
        $store = new InMemoryDerivativeStore;
        self::storeDerivatives($store);
        $listener = new ClearMediaDerivatives($store);

        $listener->handle(new \stdClass);
        $listener->handle(new MediathequeEvent(new \stdClass));
        $listener->handle(MediathequeEvent::forUid('../med_01JAB3QX'));

        $this->assertCount(3, $store->paths());
    }

    /**
     * A derivative of the media `med_01JAB3QX`, one of its poster, and one of another media.
     *
     * @return array{media: DerivativeKey, poster: DerivativeKey, other: DerivativeKey}
     */
    public static function storeDerivatives(DerivativeStore $store): array
    {
        $spec = new ImageSpec(width: 200, format: Format::Webp);
        $keys = [
            'media' => DerivativeKey::for(new ImageSource('media', 'med_01JAB3QX', '8c1f02ab'), $spec),
            'poster' => DerivativeKey::for(new ImageSource('media', 'med_01JAB3QX/poster', '5e0d9a17'), $spec),
            'other' => DerivativeKey::for(new ImageSource('media', 'med_01JAB3QY', '8c1f02ab'), $spec),
        ];
        foreach ($keys as $key) {
            $store->put($key, new RenderedImage('contents', Format::Webp, 200, 150));
        }

        return $keys;
    }
}
