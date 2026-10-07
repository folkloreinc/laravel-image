<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;

/**
 * Bad image requests answer with a client error, never a 500.
 */
class ErrorResponsesTest extends TestCase
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

        $this->app['router']->image('errors/{pattern}', [
            'as' => 'image.errors',
            'cache' => false,
        ]);
    }

    public function test_a_source_file_that_is_not_a_supported_image_returns_415()
    {
        $this->get('/errors/wrong-filters(10x10).jpg')->assertStatus(415);
    }

    public function test_an_unknown_filter_returns_404()
    {
        $this->get('/errors/image-filters(unknownfilter).jpg')->assertNotFound();
    }

    public function test_an_unparsable_filter_returns_404()
    {
        $this->get('/errors/image-filters(blur(1:2)).jpg')->assertNotFound();
    }

    public function test_a_missing_image_returns_404()
    {
        $this->get('/errors/missing-filters(10x10).jpg')->assertNotFound();
    }
}
