<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Facades\Image;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The whole path of an image, on each driver: the disk resolver reads an original on a disk,
 * the Intervention renderer renders it, DiskStore keeps the derivative on another disk, and
 * the route serves it with its caching headers.
 */
final class EndToEndTest extends TestCase
{
    private Filesystem $originals;

    private Filesystem $derivatives;

    protected function defineEnvironment($app): void
    {
        $config = $app->make(Repository::class);
        $config->set('image.url.host', 'https://img.test');
        $config->set('image.signing.keys', ['test-signing-key']);
        $config->set('image.presets', ['card' => ['width' => 800, 'ratio' => '16:9', 'widths' => [400, 800]]]);
        $config->set('image.resolvers.disk.disk', 'originals');
        $config->set('image.derivatives.disk', 'derivatives');
        $config->set('image.derivatives.path', 'cache');
    }

    protected function defineRoutes($router): void
    {
        Image::routes();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return array_combine(array_keys(self::DRIVERS), array_map(fn (string $driver) => [$driver], array_keys(self::DRIVERS)));
    }

    #[DataProvider('drivers')]
    public function test_a_preset_is_rendered_stored_and_served(string $driver): void
    {
        $this->useDriver($driver);

        $variant = Image::for('photos/landscape.jpg')->preset('card')->variant();
        $this->assertSame([800, 450, 'image/jpeg'], [$variant->width, $variant->height, $variant->mime()]);

        $response = $this->get($variant->url)->assertOk();

        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertSame(['immutable', 'max-age=31536000', 'public'], $this->cacheControl($response));
        $this->assertSame([800, 450], $this->dimensions($response->streamedContent()));

        $stored = $this->derivatives->allFiles('cache');
        $this->assertCount(1, $stored);
        $this->assertMatchesRegularExpression('#^cache/disk/photos/landscape\.jpg/[A-Za-z0-9_-]+/w800,ar-16-9\.jpg$#', $stored[0]);

        // The second request reads the stored derivative instead of rendering it again.
        $this->derivatives->put($stored[0], 'stored');
        $this->assertSame('stored', $this->get($variant->url)->assertOk()->streamedContent());

        $etag = (string) $response->headers->get('ETag');
        $this->get($variant->url, ['If-None-Match' => $etag])->assertStatus(304);
    }

    #[DataProvider('drivers')]
    public function test_variants_are_served_at_the_dimensions_they_announce(string $driver): void
    {
        $this->useDriver($driver);

        foreach (Image::for('photos/landscape.jpg')->variants('card') as $variant) {
            $response = $this->get($variant->url)->assertOk();

            $this->assertSame([$variant->width, $variant->height], $this->dimensions($response->streamedContent()), $variant->url);
        }
    }

    #[DataProvider('drivers')]
    public function test_a_signed_url_that_expires(string $driver): void
    {
        $this->useDriver($driver);
        $url = Image::for('photos/landscape.jpg')->size(300, 300)->png()->signed(600)->url();

        $response = $this->get($url)->assertOk();

        $this->assertSame('image/png', $response->headers->get('Content-Type'));
        $this->assertSame([300, 300], $this->dimensions($response->streamedContent()));
        $this->assertContains('max-age=600', $this->cacheControl($response));
        $this->get(str_replace('/w300,', '/w301,', $url))->assertForbidden();
        $this->get(substr($url, 0, (int) strpos($url, '/img/')).'/img/_/w300,h300/disk/photos/landscape.jpg.png')->assertNotFound();
    }

    #[DataProvider('drivers')]
    public function test_a_replaced_original_gets_new_urls(string $driver): void
    {
        $this->useDriver($driver);
        $before = Image::for('photos/landscape.jpg')->preset('card')->url();
        $this->get($before)->assertOk();

        $this->originals->put('photos/landscape.jpg', (string) file_get_contents(self::fixturePath('images/image.jpg')));
        touch($this->originals->path('photos/landscape.jpg'), time() + 10);
        $this->app?->make(CacheFactory::class)->store()->clear();

        $after = Image::for('photos/landscape.jpg')->preset('card')->url();
        $this->assertNotSame($before, $after);
        $this->get($after)->assertOk();
    }

    #[DataProvider('drivers')]
    public function test_redirects_to_a_public_derivatives_disk(string $driver): void
    {
        $this->useDriver($driver);
        $this->config()->set('image.delivery.serve', 'redirect');
        $this->config()->set('filesystems.disks.derivatives', ['driver' => 'local', 'visibility' => 'public']);
        $this->derivatives = Storage::fake('derivatives', ['visibility' => 'public', 'url' => 'https://bucket.test']);
        $this->app?->forgetInstance(DerivativeStore::class);

        $response = $this->get(Image::for('photos/landscape.jpg')->preset('card')->url());

        $response->assertRedirect();
        $this->assertStringStartsWith('https://bucket.test/cache/disk/photos/landscape.jpg/', (string) $response->headers->get('Location'));
        $this->assertCount(1, $this->derivatives->allFiles('cache'));
    }

    private function useDriver(string $driver): void
    {
        $this->driverOrSkip($driver);
        $this->config()->set('image.renderer.driver', $driver);

        $this->originals = Storage::fake('originals');
        $this->derivatives = Storage::fake('derivatives');
        $this->originals->put('photos/landscape.jpg', (string) file_get_contents(self::fixturePath('images/landscape.jpg')));
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(string $contents): array
    {
        $size = getimagesizefromstring($contents);
        $this->assertIsArray($size, 'The response is an image.');

        return [$size[0], $size[1]];
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<string>
     */
    private function cacheControl(TestResponse $response): array
    {
        $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($directives);

        return $directives;
    }

    private function config(): Repository
    {
        $config = $this->app?->make(Repository::class);
        $this->assertInstanceOf(Repository::class, $config);

        return $config;
    }
}
