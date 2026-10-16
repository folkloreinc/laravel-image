<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Storage;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Exceptions\RenderInProgress;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\RenderLock;
use Folklore\Image\Storage\StoredDerivative;
use Folklore\Image\Testing\InMemoryDerivativeStore;
use Folklore\Image\Tests\TestCase;
use Illuminate\Cache\ArrayStore;

final class RenderLockTest extends TestCase
{
    private ArrayStore $locks;

    private int $renders = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->locks = new ArrayStore;
    }

    public function test_a_missing_derivative_is_rendered_and_stored(): void
    {
        $store = new InMemoryDerivativeStore;

        $stored = $this->lock()->remember($store, $this->key(), $this->render(...));

        $this->assertSame(1, $this->renders);
        $this->assertSame('rendered', $stored->contents());
        $this->assertSame('rendered', $store->get($this->key())?->contents());
    }

    public function test_a_stored_derivative_is_not_rendered_again(): void
    {
        $store = new InMemoryDerivativeStore;
        $store->put($this->key(), new RenderedImage('stored', Format::Webp, 200, 150));

        $stored = $this->lock()->remember($store, $this->key(), $this->render(...));

        $this->assertSame(0, $this->renders);
        $this->assertSame('stored', $stored->contents());
    }

    public function test_a_request_that_waited_reads_what_the_first_one_stored(): void
    {
        // The derivative is missing when the request checks, and stored by another request by
        // the time it holds the lock.
        $store = new class implements DerivativeStore
        {
            private int $gets = 0;

            private InMemoryDerivativeStore $store;

            public function __construct()
            {
                $this->store = new InMemoryDerivativeStore;
            }

            public function get(DerivativeKey $key): ?StoredDerivative
            {
                if ($this->gets++ === 0) {
                    return null;
                }

                return $this->store->get($key) ?? $this->store->put($key, new RenderedImage('stored by another request', Format::Webp, 200, 150));
            }

            public function put(DerivativeKey $key, RenderedImage $image): StoredDerivative
            {
                return $this->store->put($key, $image);
            }

            public function clear(ImageSource $source): int
            {
                return $this->store->clear($source);
            }

            public function url(DerivativeKey $key): ?string
            {
                return null;
            }
        };

        $stored = $this->lock()->remember($store, $this->key(), $this->render(...));

        $this->assertSame(0, $this->renders);
        $this->assertSame('stored by another request', $stored->contents());
    }

    public function test_the_lock_is_released_after_rendering_even_when_it_fails(): void
    {
        try {
            $this->lock()->remember(new InMemoryDerivativeStore, $this->key(), fn () => throw new RenderFailed('Broken source.'));
        } catch (RenderFailed) {
        }

        $stored = $this->lock(wait: 0)->remember(new InMemoryDerivativeStore, $this->key(), $this->render(...));

        $this->assertSame('rendered', $stored->contents());
    }

    public function test_a_render_held_by_another_request_times_out(): void
    {
        $this->locks->lock('folklore-image:render:'.hash('sha256', $this->key()->path()), 60)->get();

        $this->expectException(RenderInProgress::class);

        try {
            $this->lock(wait: 1)->remember(new InMemoryDerivativeStore, $this->key(), $this->render(...));
        } finally {
            $this->assertSame(0, $this->renders);
        }
    }

    public function test_the_render_lock_is_bound(): void
    {
        $this->assertInstanceOf(RenderLock::class, $this->app?->make(RenderLock::class));
    }

    private function lock(int $wait = 5): RenderLock
    {
        return new RenderLock($this->locks, 60, $wait);
    }

    private function render(): RenderedImage
    {
        $this->renders++;

        return new RenderedImage('rendered', Format::Webp, 200, 150);
    }

    private function key(): DerivativeKey
    {
        return DerivativeKey::for(new ImageSource('disk', 'uploads/photo.jpg', 'v1', mime: 'image/jpeg'), new ImageSpec(width: 200, format: Format::Webp));
    }
}
