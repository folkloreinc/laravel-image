<?php

namespace Folklore\Image\Tests\Unit;

use Folklore\Image\Tests\TestCase;

/**
 * The configured `image.memory_limit` is a minimum: it must never lower a higher limit.
 */
class MemoryLimitTest extends TestCase
{
    protected $originalMemoryLimit;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('image.memory_limit', '256M');
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

        $this->originalMemoryLimit = ini_get('memory_limit');
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->originalMemoryLimit);

        parent::tearDown();
    }

    public function test_make_does_not_lower_a_higher_limit()
    {
        ini_set('memory_limit', '1G');

        app('image')->source()->make('image.jpg', ['width' => 10]);

        $this->assertEquals('1G', ini_get('memory_limit'));
    }

    public function test_make_raises_a_lower_limit()
    {
        ini_set('memory_limit', '200M');

        app('image')->source()->make('image.jpg', ['width' => 10]);

        $this->assertEquals('256M', ini_get('memory_limit'));
    }

    public function test_make_keeps_an_unlimited_limit()
    {
        ini_set('memory_limit', '-1');

        app('image')->source()->make('image.jpg', ['width' => 10]);

        $this->assertEquals('-1', ini_get('memory_limit'));
    }

    public function test_serving_an_image_does_not_lower_a_higher_limit()
    {
        $this->app['router']->image('memory/{pattern}', [
            'as' => 'image.memory',
            'cache' => false,
        ]);
        ini_set('memory_limit', '1G');

        $this->get('/memory/image-filters(10x10).jpg')->assertOk();

        $this->assertEquals('1G', ini_get('memory_limit'));
    }

    public function test_the_url_cannot_raise_the_limit()
    {
        $this->app['router']->image('memory/{pattern}', [
            'as' => 'image.memory',
            'cache' => false,
        ]);
        ini_set('memory_limit', '200M');

        $this->get('/memory/image-filters(memory_limit(1G)-10x10).jpg')->assertOk();

        // The configured minimum applies, not the one from the URL.
        $this->assertEquals('256M', ini_get('memory_limit'));
    }

    public function test_make_and_route_filters_can_still_raise_the_limit()
    {
        ini_set('memory_limit', '200M');
        app('image')->source()->make('image.jpg', ['width' => 10, 'memory_limit' => '512M']);
        $this->assertEquals('512M', ini_get('memory_limit'));

        $this->app['router']->image('memory/{pattern}', [
            'as' => 'image.memory',
            'cache' => false,
            'filters' => ['memory_limit' => '384M'],
        ]);
        ini_set('memory_limit', '200M');

        $this->get('/memory/image-filters(10x10).jpg')->assertOk();

        $this->assertEquals('384M', ini_get('memory_limit'));
    }
}
