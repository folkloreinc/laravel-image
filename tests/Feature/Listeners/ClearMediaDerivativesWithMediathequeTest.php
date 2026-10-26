<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Listeners;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Listeners\ClearMediaDerivatives;
use Folklore\Image\Testing\InMemoryDerivativeStore;
use Folklore\Image\Tests\Doubles\MediathequeEvent;
use Folklore\Image\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * With the events of laravel-mediatheque 2, which stand in for them under their names. Each
 * test runs in its own process, so the other tests run without them.
 */
#[RunTestsInSeparateProcesses]
final class ClearMediaDerivativesWithMediathequeTest extends TestCase
{
    protected function setUp(): void
    {
        foreach (ClearMediaDerivatives::EVENTS as $event) {
            class_alias(MediathequeEvent::class, $event);
        }

        parent::setUp();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function events(): array
    {
        return array_combine(ClearMediaDerivatives::EVENTS, array_map(fn (string $event) => [$event], ClearMediaDerivatives::EVENTS));
    }

    #[DataProvider('events')]
    public function test_the_event_clears_the_derivatives_of_the_media(string $event): void
    {
        $this->assertTrue(Event::hasListeners($event));
        $store = new InMemoryDerivativeStore;
        $this->app?->instance(DerivativeStore::class, $store);
        $keys = ClearMediaDerivativesTest::storeDerivatives($store);

        // As Laravel dispatches an event object: by its class name.
        Event::dispatch($event, [MediathequeEvent::forUid('med_01JAB3QX')]);

        $this->assertSame([$keys['other']->path()], $store->paths());
    }
}
