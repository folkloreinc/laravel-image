<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Imagine\Image\Point;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Every encoded image uses the quality configured for its format, whether the route
 * caches or not.
 */
class OutputQualityTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('image.routes', [
            'map' => null,
            'domain' => null,
            'namespace' => null,
            'middleware' => [],
            'controller' => '\Folklore\Image\Http\ImageController@serve',
            'pattern_name' => 'image_pattern',
            'cache_middleware' => 'image.middleware.cache',
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->cachePath().'/cached');

        parent::tearDown();
    }

    protected function routes(array $config = []): void
    {
        $this->app['router']->image('uncached/{pattern}', array_merge(['as' => 'image.uncached', 'cache' => false], $config));
        $this->app['router']->image('cached/{pattern}', array_merge([
            'as' => 'image.cached',
            'cache' => true,
            'cache_path' => $this->cachePath(),
        ], $config));
    }

    public function test_jpeg_uses_the_default_quality_with_and_without_the_cache()
    {
        $this->routes();
        $expected = $this->expected('image.jpg', 'jpg', ['quality' => 82]);

        $this->assertSame($expected, $this->content($this->get('/uncached/image-filters(100x100).jpg')));
        $this->assertSame($expected, $this->content($this->get('/cached/image-filters(100x100).jpg')));
    }

    public function test_webp_uses_the_default_quality()
    {
        $this->routes();
        $expected = $this->expected('image.jpg', 'webp', ['quality' => 80]);

        $this->assertSame($expected, $this->content($this->get('/uncached/image-filters(100x100).jpg.webp')));
        $this->assertSame($expected, $this->content($this->get('/cached/image-filters(100x100).jpg.webp')));
    }

    public function test_png_is_compressed_losslessly()
    {
        $this->routes();
        $expected = $this->expected('image.png', 'png', ['png_compression_level' => 9]);

        $uncached = $this->content($this->get('/uncached/image-filters(100x100).png'));
        $this->assertSame($expected, $uncached);
        $this->assertSame($expected, $this->content($this->get('/cached/image-filters(100x100).png')));

        // Lossless: the pixels match the unencoded derivative.
        $original = app('image')->make('image.png', ['width' => 100, 'height' => 100]);
        $decoded = app('image')->getImagine()->load($uncached);
        $this->assertEquals(
            (string) $original->getColorAt(new Point(50, 50)),
            (string) $decoded->getColorAt(new Point(50, 50))
        );
    }

    public function test_the_config_sets_the_quality_per_format()
    {
        $this->app['config']->set('image.quality.jpeg', 50);
        $this->routes();

        $expected = $this->expected('image.jpg', 'jpg', ['quality' => 50]);
        $this->assertSame($expected, $this->content($this->get('/uncached/image-filters(100x100).jpg')));
        $this->assertSame($expected, $this->content($this->get('/cached/image-filters(100x100).jpg')));
    }

    public function test_a_route_quality_overrides_the_config_with_and_without_the_cache()
    {
        $this->routes(['quality' => 60]);

        $expected = $this->expected('image.jpg', 'jpg', ['quality' => 60]);
        $this->assertSame($expected, $this->content($this->get('/uncached/image-filters(100x100).jpg')));
        $this->assertSame($expected, $this->content($this->get('/cached/image-filters(100x100).jpg')));
    }

    public function test_saving_through_a_source_uses_the_default_quality()
    {
        $handler = app('image')->source();
        $image = $handler->make('image.jpg', ['width' => 100, 'height' => 100]);
        (new Filesystem)->ensureDirectoryExists($this->cachePath().'/cached');
        $handler->save($image, 'cache/cached/saved.jpg');

        $this->assertSame(
            $this->expected('image.jpg', 'jpg', ['quality' => 82]),
            file_get_contents($this->cachePath().'/cached/saved.jpg')
        );
    }

    protected function expected(string $path, string $format, array $options): string
    {
        $image = app('image')->make($path, ['width' => 100, 'height' => 100]);

        return $image->get($format, array_merge(['flatten' => true], $options));
    }

    protected function cachePath(): string
    {
        return __DIR__.'/../fixture/cache';
    }

    protected function content($response): string
    {
        $response->assertOk();
        $baseResponse = $response->baseResponse;
        if (! $baseResponse instanceof StreamedResponse) {
            return $baseResponse->getContent();
        }

        ob_start();
        $baseResponse->sendContent();

        return ob_get_clean();
    }
}
