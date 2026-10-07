<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HEIC sources (iPhone photos). The fixture `heic/photo.heic` is 120×80.
 *
 * Reading HEIC needs Imagick built with libheif and its HEVC decoder; the tests that
 * decode it are skipped otherwise, and run in the CI job that installs them.
 */
class HeicTest extends TestCase
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

    public function test_sources_detect_the_heic_format()
    {
        $this->assertEquals('heic', app('image')->source('local')->format('heic/photo.heic'));
    }

    public function test_a_heic_source_is_served_as_jpeg()
    {
        $this->useImagickWithHeic();
        $this->app['router']->image('photos/{pattern}', ['as' => 'image.photos', 'cache' => false]);

        $response = $this->get('/photos/heic/photo-filters(60x40).heic');

        $response->assertOk();
        $this->assertEquals('image/jpeg', $response->headers->get('Content-Type'));
        $size = getimagesizefromstring($this->content($response));
        $this->assertEquals([60, 40, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);
    }

    public function test_a_heic_source_is_served_in_the_requested_format()
    {
        $this->useImagickWithHeic();
        $this->app['router']->image('photos/{pattern}', ['as' => 'image.photos', 'cache' => false]);

        $response = $this->get('/photos/heic/photo-filters(60x40).heic.png');

        $response->assertOk();
        $this->assertEquals('image/png', $response->headers->get('Content-Type'));
        $this->assertEquals('image/png', getimagesizefromstring($this->content($response))['mime']);
    }

    public function test_the_route_cache_writes_a_jpeg()
    {
        $this->useImagickWithHeic();
        $cachePath = public_path('cache/heic');
        $this->app['router']->image('photos/{pattern}', ['as' => 'image.photos', 'cache' => true, 'cache_path' => $cachePath]);

        try {
            $response = $this->get('/photos/heic/photo-filters(60x40).heic');

            $response->assertOk();
            $cached = $cachePath.'/photos/heic/photo-filters(60x40).heic';
            $this->assertFileExists($cached);
            $this->assertEquals('image/jpeg', getimagesize($cached)['mime']);
        } finally {
            (new Filesystem)->deleteDirectory($cachePath);
        }
    }

    public function test_a_driver_that_cannot_read_heic_answers_415()
    {
        $this->app['config']->set('image.driver', 'gd');
        $this->app['router']->image('photos/{pattern}', ['as' => 'image.photos', 'cache' => false]);

        $this->get('/photos/heic/photo-filters(60x40).heic')->assertStatus(415);
    }

    protected function useImagickWithHeic(): void
    {
        if (! extension_loaded('imagick') || count(\Imagick::queryFormats('HEIC')) === 0) {
            $this->markTestSkipped('Imagick with HEIC support is not installed.');
        }
        try {
            new \Imagick(public_path('heic/photo.heic'));
        } catch (\ImagickException $e) {
            $this->markTestSkipped('Imagick can\'t decode HEIC here: '.$e->getMessage());
        }

        $this->app['config']->set('image.driver', 'imagick');
        foreach (['image', 'image.imagine', 'image.imagine_manager', 'image.source'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
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
}
