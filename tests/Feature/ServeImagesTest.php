<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves images through real HTTP routes, from both sources, with and without the cache.
 */
class ServeImagesTest extends TestCase
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
        (new Filesystem)->deleteDirectory($this->cachePath().'/served');

        parent::tearDown();
    }

    public function test_it_serves_a_resized_image_from_the_local_source()
    {
        $this->app['router']->image('local/{pattern}', [
            'as' => 'image.local',
            'source' => 'local',
            'cache' => false,
        ]);

        $response = $this->get('/local/image-filters(100x50-crop).jpg');

        $response->assertOk();
        $this->assertEquals('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertImageSize(100, 50, $this->content($response));
    }

    public function test_it_serves_a_resized_image_from_the_filesystem_source()
    {
        $this->app['router']->image('disk/{pattern}', [
            'as' => 'image.disk',
            'source' => 'filesystem',
            'cache' => false,
        ]);

        $response = $this->get('/disk/image-filters(80x80-crop).png');

        $response->assertOk();
        $this->assertEquals('image/png', $response->headers->get('Content-Type'));
        $this->assertImageSize(80, 80, $this->content($response));
    }

    public function test_it_converts_the_format_with_a_format_extension()
    {
        $this->app['router']->image('local/{pattern}', [
            'as' => 'image.local',
            'cache' => false,
        ]);

        $response = $this->get('/local/image-filters(60x60-crop).jpg.png');

        $response->assertOk();
        $this->assertEquals('image/png', $response->headers->get('Content-Type'));
        $this->assertImageSize(60, 60, $this->content($response));
    }

    public function test_it_writes_the_image_to_the_cache_path_when_the_cache_is_on()
    {
        $this->app['router']->image('served/{pattern}', [
            'as' => 'image.cached',
            'cache' => true,
            'cache_path' => $this->cachePath(),
        ]);

        $response = $this->get('/served/image-filters(40x40-crop).jpg');

        $response->assertOk();
        $cachedFile = $this->cachePath().'/served/image-filters(40x40-crop).jpg';
        $this->assertFileExists($cachedFile);
        $this->assertImageSize(40, 40, file_get_contents($cachedFile));
        $this->assertEquals(file_get_contents($cachedFile), $this->content($response));
    }

    public function test_it_does_not_write_anything_when_the_cache_is_off()
    {
        $this->app['router']->image('served/{pattern}', [
            'as' => 'image.uncached',
            'cache' => false,
            'cache_path' => $this->cachePath(),
        ]);

        $this->get('/served/image-filters(40x40-crop).jpg')->assertOk();

        $this->assertDirectoryDoesNotExist($this->cachePath().'/served');
    }

    public function test_it_serves_urls_with_negative_values_and_crop_positions()
    {
        $this->app['router']->image('local/{pattern}', [
            'as' => 'image.local',
            'source' => 'local',
            'cache' => false,
        ]);

        // The URLs the generators produce for these filters (the fixture is 80×40)
        $rotated = app('image')->url('orientation/orientation-1.jpg', null, null, ['rotate' => -90]);
        $this->assertEquals('/orientation/orientation-1-filters(rotate(-90)).jpg', $rotated);
        $topLeft = app('image')->url('image.jpg', 100, 50, ['crop' => 'top_left']);
        $this->assertEquals('/image-filters(100x50-crop(top_left)).jpg', $topLeft);

        $response = $this->get('/local'.$rotated);
        $response->assertOk();
        $this->assertImageSize(40, 80, $this->content($response));

        $response = $this->get('/local'.$topLeft);
        $response->assertOk();
        $this->assertImageSize(100, 50, $this->content($response));
    }

    public function test_it_returns_a_404_for_a_missing_image()
    {
        $this->app['router']->image('local/{pattern}', [
            'as' => 'image.local',
            'cache' => false,
        ]);

        $this->get('/local/missing-filters(100x100).jpg')->assertNotFound();
    }

    protected function cachePath(): string
    {
        return __DIR__.'/../fixture/cache';
    }

    protected function content($response): string
    {
        $baseResponse = $response->baseResponse;
        if (! $baseResponse instanceof StreamedResponse) {
            return $baseResponse->getContent();
        }

        ob_start();
        $baseResponse->sendContent();

        return ob_get_clean();
    }

    protected function assertImageSize(int $width, int $height, string $content): void
    {
        $size = getimagesizefromstring($content);

        $this->assertNotFalse($size, 'The response is not a valid image.');
        $this->assertEquals([$width, $height], [$size[0], $size[1]]);
    }
}
