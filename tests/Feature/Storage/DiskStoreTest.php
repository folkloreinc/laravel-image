<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Storage;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\DiskStore;
use Folklore\Image\Testing\Contracts\DerivativeStoreContractTests;
use Folklore\Image\Testing\InMemoryDerivativeStore;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\Attributes\DefineEnvironment;

/**
 * DiskStore on a local disk. The S3-compatible disk is tested in S3DiskStoreTest.
 */
final class DiskStoreTest extends TestCase
{
    use DerivativeStoreContractTests;

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Storage::fake('derivatives');
    }

    protected function store(bool $public = false): DiskStore
    {
        return new DiskStore($this->disk, 'cache/derivatives', $public);
    }

    public function test_derivatives_are_stored_at_their_key_under_the_directory(): void
    {
        $key = DerivativeKey::for(new ImageSource('media', 'med_01JAB3QX', '8c1f02ab'), new ImageSpec(width: 800, height: 450, format: Format::Webp));

        $stored = $this->store()->put($key, new RenderedImage('webp', Format::Webp, 800, 450));

        $this->assertSame('cache/derivatives/media/med_01JAB3QX/8c1f02ab/w800,h450.webp', $this->store()->pathOf($key));
        $this->assertSame('webp', $this->disk->get('cache/derivatives/media/med_01JAB3QX/8c1f02ab/w800,h450.webp'));
        $this->assertNotNull($stored->lastModified);
        $this->assertSame($stored->etag(), $this->store()->get($key)?->etag());
    }

    public function test_a_read_derivative_streams_from_the_disk(): void
    {
        $key = $this->key();
        $this->store()->put($key, new RenderedImage('contents', Format::Webp, 200, 150));

        $read = $this->store()->get($key);
        $this->assertNotNull($read);
        $stream = $read->stream();

        $this->assertSame('contents', stream_get_contents($stream));
        fclose($stream);
        $this->assertSame($this->disk->lastModified($this->store()->pathOf($key)), $read->lastModified);
    }

    public function test_clearing_a_source_removes_its_directory(): void
    {
        $key = $this->key();
        $this->store()->put($key, new RenderedImage('contents', Format::Webp, 200, 150));

        $this->store()->clear(new ImageSource('disk', 'uploads/photo.jpg'));

        $this->assertFalse($this->disk->exists('cache/derivatives/disk/uploads/photo.jpg'));
        $this->assertTrue($this->disk->exists('cache/derivatives/disk/uploads'));
    }

    public function test_clearing_a_source_without_derivatives_deletes_nothing(): void
    {
        $this->assertSame(0, $this->store()->clear(new ImageSource('disk', 'uploads/photo.jpg')));
    }

    public function test_a_public_disk_gives_urls(): void
    {
        $key = $this->key();

        $this->assertNull($this->store()->url($key));
        $this->assertStringEndsWith('/cache/derivatives/disk/uploads/photo.jpg/v1/w200.webp', (string) $this->store(public: true)->url($key));
    }

    public function test_the_store_is_bound_to_the_derivatives_disk(): void
    {
        $store = $this->app?->make(DerivativeStore::class);

        $this->assertInstanceOf(DiskStore::class, $store);
        $this->assertSame($store, $this->app?->make(DerivativeStore::class));
        $this->assertSame('derivatives/disk/uploads/photo.jpg/v1/w200.webp', $store->pathOf($this->key()));
        $this->assertNotNull($store->url($this->key()), 'The public disk gives URLs.');
    }

    #[DefineEnvironment('usePrivateDisk')]
    public function test_a_private_disk_gives_no_urls(): void
    {
        $store = $this->app?->make(DerivativeStore::class);

        $this->assertInstanceOf(DiskStore::class, $store);
        $this->assertSame('images/disk/uploads/photo.jpg/v1/w200.webp', $store->pathOf($this->key()));
        $this->assertNull($store->url($this->key()));
    }

    #[DefineEnvironment('useAnotherStore')]
    public function test_the_store_can_be_a_class(): void
    {
        $this->assertInstanceOf(InMemoryDerivativeStore::class, $this->app?->make(DerivativeStore::class));
    }

    protected function usePrivateDisk(Application $app): void
    {
        $app->make(Repository::class)->set(['image.derivatives.disk' => 'local', 'image.derivatives.path' => 'images']);
    }

    protected function useAnotherStore(Application $app): void
    {
        $app->make(Repository::class)->set('image.derivatives.store', InMemoryDerivativeStore::class);
    }

    private function key(): DerivativeKey
    {
        return DerivativeKey::for(new ImageSource('disk', 'uploads/photo.jpg', 'v1', mime: 'image/jpeg'), new ImageSpec(width: 200, format: Format::Webp));
    }
}
