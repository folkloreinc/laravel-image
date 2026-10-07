<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * SVG sources: format detection and serving through image routes. The fixture
 * `svg/shape.svg` is 200×100.
 */
class SvgTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        copy(public_path('svg/shape.svg'), public_path('filesystem/shape.svg'));
    }

    protected function tearDown(): void
    {
        @unlink(public_path('filesystem/shape.svg'));

        parent::tearDown();
    }

    public function test_sources_detect_the_svg_format()
    {
        $this->assertEquals('svg', app('image')->source('local')->format('svg/shape.svg'));
        $this->assertEquals('svg', app('image')->source('filesystem')->format('shape.svg'));
    }

    public function test_a_route_serves_a_resized_svg()
    {
        $this->app['router']->image('vector/{pattern}', ['as' => 'image.vector', 'cache' => false]);

        $response = $this->get('/vector/svg/shape-filters(100x50).svg');

        $response->assertOk();
        $this->assertEquals('image/svg+xml', $response->headers->get('Content-Type'));
        $svg = simplexml_load_string($this->content($response));
        $this->assertNotFalse($svg);
        $this->assertEquals(['100', '50'], [(string) $svg['width'], (string) $svg['height']]);
    }

    public function test_a_route_serves_an_svg_without_filters()
    {
        $this->app['router']->image('vector/{pattern}', ['as' => 'image.vector', 'cache' => false]);

        $response = $this->get('/vector/svg/shape.svg');

        $response->assertOk();
        $this->assertEquals('image/svg+xml', $response->headers->get('Content-Type'));
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
