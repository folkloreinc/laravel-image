<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Filters\Resize;
use Folklore\Image\Tests\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The `upscale` option: null keeps the v1 behaviour (crops upscale, plain resizes
 * don't), true always upscales, false never does. The fixture image is 300×300.
 */
class UpscaleTest extends TestCase
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

    public function test_the_resize_filter_keeps_the_v1_behaviour_by_default()
    {
        $image = app('image')->open('image.jpg');

        $this->assertSize(600, 300, (new Resize)->apply($image, ['width' => 600, 'height' => 300, 'crop' => true]));
        $this->assertSize(300, 300, (new Resize)->apply($image, ['width' => 600, 'height' => 600]));
    }

    public function test_the_resize_filter_never_upscales_with_upscale_false()
    {
        $image = app('image')->open('image.jpg');

        // The crop keeps the requested 2:1 ratio, at the largest size the source allows.
        $this->assertSize(300, 150, (new Resize)->apply($image, ['width' => 600, 'height' => 300, 'crop' => true, 'upscale' => false]));
        $this->assertSize(150, 300, (new Resize)->apply($image, ['width' => 200, 'height' => 400, 'crop' => true, 'upscale' => false]));
        $this->assertSize(300, 300, (new Resize)->apply($image, ['width' => 600, 'height' => 600, 'upscale' => false]));

        // Smaller sizes are not affected.
        $this->assertSize(100, 50, (new Resize)->apply($image, ['width' => 100, 'height' => 50, 'crop' => true, 'upscale' => false]));
    }

    public function test_the_resize_filter_always_upscales_with_upscale_true()
    {
        $image = app('image')->open('image.jpg');

        $this->assertSize(600, 300, (new Resize)->apply($image, ['width' => 600, 'height' => 300, 'crop' => true, 'upscale' => true]));
        $this->assertSize(600, 600, (new Resize)->apply($image, ['width' => 600, 'height' => 600, 'upscale' => true]));
    }

    public function test_make_accepts_the_upscale_option()
    {
        $image = app('image')->make('image.jpg', ['width' => 600, 'height' => 300, 'crop' => true, 'upscale' => false]);

        $this->assertSize(300, 150, $image);
    }

    public function test_the_config_sets_the_default()
    {
        $this->app['config']->set('image.upscale', false);

        $image = app('image')->make('image.jpg', ['width' => 600, 'height' => 300, 'crop' => true]);

        $this->assertSize(300, 150, $image);
    }

    public function test_a_route_can_turn_upscaling_off_and_the_url_cannot_turn_it_back_on()
    {
        $this->app['router']->image('bounded/{pattern}', ['as' => 'image.bounded', 'cache' => false, 'upscale' => false]);
        $this->app['router']->image('default/{pattern}', ['as' => 'image.default', 'cache' => false]);

        $this->assertResponseSize(300, 150, $this->get('/bounded/image-filters(600x300-crop).jpg'));
        $this->assertResponseSize(300, 150, $this->get('/bounded/image-filters(600x300-crop-upscale).jpg'));
        $this->assertResponseSize(600, 300, $this->get('/default/image-filters(600x300-crop).jpg'));
    }

    public function test_a_route_with_a_null_upscale_uses_the_config()
    {
        $this->app['config']->set('image.upscale', false);
        $this->app['router']->image('inherit/{pattern}', ['as' => 'image.inherit', 'cache' => false, 'upscale' => null]);

        $this->assertResponseSize(300, 150, $this->get('/inherit/image-filters(600x300-crop).jpg'));
    }

    public function test_an_upscale_filter_in_the_url_does_not_count_against_the_restrictions()
    {
        $this->app['router']->image('strict/{pattern}', [
            'as' => 'image.strict',
            'cache' => false,
            'allow_filters' => false,
            'restrictions' => ['mode' => 'enforce'],
        ]);

        $this->assertResponseSize(600, 300, $this->get('/strict/image-filters(600x300-crop-upscale).jpg'));
    }

    protected function assertSize(int $width, int $height, $image): void
    {
        $this->assertEquals([$width, $height], [$image->getSize()->getWidth(), $image->getSize()->getHeight()]);
    }

    protected function assertResponseSize(int $width, int $height, $response): void
    {
        $response->assertOk();
        $baseResponse = $response->baseResponse;
        if ($baseResponse instanceof StreamedResponse) {
            ob_start();
            $baseResponse->sendContent();
            $content = ob_get_clean();
        } else {
            $content = $baseResponse->getContent();
        }
        $size = getimagesizefromstring($content);
        $this->assertEquals([$width, $height], [$size[0], $size[1]]);
    }
}
