<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;

/**
 * A site that published config/image.php long ago, or kept only a few keys, still
 * gets the package defaults for the nested keys it doesn't set.
 */
class PartialConfigTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // An old published config: `url` without the filters formats and patterns,
        // `routes` without the pattern name and cache middleware.
        $app['config']->set('image.url', [
            'format' => '{dirname}/{basename}{filters}.{extension}',
        ]);
        $app['config']->set('image.routes', [
            'map' => null,
            'controller' => '\Folklore\Image\Http\ImageController@serve',
        ]);
        $app['config']->set('image.restrictions', [
            'max_width' => 500,
        ]);
    }

    public function test_missing_nested_keys_get_the_defaults()
    {
        $config = $this->app['config'];

        $this->assertEquals('-filters({filter})', $config->get('image.url.filters_format'));
        $this->assertEquals('-', $config->get('image.url.filter_separator'));
        $this->assertNotEmpty($config->get('image.url.placeholders_patterns.extension'));
        $this->assertEquals('image_pattern', $config->get('image.routes.pattern_name'));
        $this->assertEquals('image.middleware.cache', $config->get('image.routes.cache_middleware'));
        $this->assertEquals('log', $config->get('image.restrictions.mode'));
    }

    public function test_keys_the_site_sets_keep_their_value()
    {
        $config = $this->app['config'];

        $this->assertEquals('{dirname}/{basename}{filters}.{extension}', $config->get('image.url.format'));
        $this->assertNull($config->get('image.routes.map'));
        $this->assertEquals(500, $config->get('image.restrictions.max_width'));
    }

    public function test_urls_are_generated_and_served()
    {
        $this->assertEquals('/image-filters(100x50-crop).jpg', app('image')->url('image.jpg', 100, 50, ['crop' => true]));

        $this->app['router']->image('partial/{pattern}', ['as' => 'image.partial', 'cache' => false]);

        $this->get('/partial/image-filters(100x50-crop).jpg')->assertOk();
    }
}
