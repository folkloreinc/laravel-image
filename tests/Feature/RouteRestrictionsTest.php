<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;
use Illuminate\Support\Facades\Log;

/**
 * Route options (`allow_size`, `allow_filters`, `disallow_filters`, `headers`) and size
 * limits, in `log` mode (report and serve) and `enforce` mode (404).
 */
class RouteRestrictionsTest extends TestCase
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

    protected function route(array $config, string $mode = 'enforce'): void
    {
        $this->app['router']->image('restricted/{pattern}', array_merge([
            'as' => 'image.restricted',
            'cache' => false,
            'restrictions' => ['mode' => $mode],
        ], $config));
    }

    public function test_allow_size_false_rejects_a_size()
    {
        $this->route(['allow_size' => false]);

        $this->get('/restricted/image-filters(grayscale).jpg')->assertOk();
        $this->get('/restricted/image-filters(100x100).jpg')->assertNotFound();
        $this->get('/restricted/image-filters(100x100-crop).jpg')->assertNotFound();
    }

    public function test_allow_filters_list_only_accepts_those_filters()
    {
        $this->route(['allow_filters' => ['grayscale']]);

        $this->get('/restricted/image-filters(100x100-grayscale).jpg')->assertOk();
        $this->get('/restricted/image-filters(negative).jpg')->assertNotFound();
    }

    public function test_allow_filters_false_rejects_every_filter_but_keeps_sizes()
    {
        $this->route(['allow_filters' => false]);

        $this->get('/restricted/image-filters(100x100-crop).jpg')->assertOk();
        $this->get('/restricted/image-filters(grayscale).jpg')->assertNotFound();
    }

    public function test_disallow_filters_rejects_those_filters()
    {
        $this->route(['disallow_filters' => ['negative']]);

        $this->get('/restricted/image-filters(grayscale).jpg')->assertOk();
        $this->get('/restricted/image-filters(grayscale-negative).jpg')->assertNotFound();
    }

    public function test_max_width_and_max_height()
    {
        $this->route(['restrictions' => ['mode' => 'enforce', 'max_width' => 100, 'max_height' => 50]]);

        $this->get('/restricted/image-filters(100x50).jpg')->assertOk();
        $this->get('/restricted/image-filters(101x50).jpg')->assertNotFound();
        $this->get('/restricted/image-filters(100x51).jpg')->assertNotFound();
        $this->get('/restricted/image-filters(101x_).jpg')->assertNotFound();
    }

    public function test_max_pixels()
    {
        $this->route(['restrictions' => ['mode' => 'enforce', 'max_pixels' => 5000]]);

        $this->get('/restricted/image-filters(100x50-crop).jpg')->assertOk();
        $this->get('/restricted/image-filters(101x50-crop).jpg')->assertNotFound();
    }

    public function test_max_filters()
    {
        $this->route(['restrictions' => ['mode' => 'enforce', 'max_filters' => 1]]);

        $this->get('/restricted/image-filters(100x100-crop-grayscale).jpg')->assertOk();
        $this->get('/restricted/image-filters(grayscale-negative).jpg')->assertNotFound();
    }

    public function test_global_limits_apply_and_routes_can_override_them()
    {
        $this->app['config']->set('image.restrictions', [
            'mode' => 'enforce',
            'max_width' => 100,
        ]);
        $this->app['router']->image('global/{pattern}', ['as' => 'image.global', 'cache' => false]);
        $this->route(['restrictions' => ['max_width' => 200]]);

        $this->get('/global/image-filters(150x_).jpg')->assertNotFound();
        $this->get('/restricted/image-filters(150x_).jpg')->assertOk();
    }

    public function test_log_mode_reports_the_violation_and_still_serves_the_image()
    {
        Log::spy();
        $this->route(['allow_size' => false, 'disallow_filters' => ['negative']], 'log');

        $this->get('/restricted/image-filters(100x100-negative).jpg')->assertOk();

        Log::shouldHaveReceived('warning')->once()->withArgs(function ($message, $context) {
            return str_contains($message, 'restrictions')
                && $context['path'] === 'image-filters(100x100-negative).jpg'
                && count($context['violations']) === 2;
        });
    }

    public function test_log_mode_is_the_default_and_logs_nothing_for_allowed_requests()
    {
        Log::spy();
        $this->app['router']->image('default/{pattern}', [
            'as' => 'image.default',
            'cache' => false,
            'allow_size' => false,
        ]);

        $this->get('/default/image-filters(grayscale).jpg')->assertOk();
        Log::shouldNotHaveReceived('warning');

        $this->get('/default/image-filters(100x100).jpg')->assertOk();
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_route_headers_are_added_to_the_response()
    {
        $this->route(['headers' => ['X-Image-Route' => 'restricted']]);

        $this->get('/restricted/image-filters(grayscale).jpg')
            ->assertOk()
            ->assertHeader('X-Image-Route', 'restricted');
    }
}
