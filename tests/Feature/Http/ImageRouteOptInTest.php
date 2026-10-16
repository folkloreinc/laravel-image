<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Http;

use Folklore\Image\Facades\Image;
use Folklore\Image\Tests\Feature\ImageTestCase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

final class ImageRouteOptInTest extends ImageTestCase
{
    public function test_no_route_is_registered_until_a_project_asks(): void
    {
        Image::fake();

        $this->assertFalse(Route::has('image'));
        $this->get(Image::for($this->photo)->preset('card')->url())->assertNotFound();
        Image::assertNothingRendered();
    }

    public function test_route_image_registers_the_route(): void
    {
        Image::fake();
        $router = $this->app?->make(Router::class);
        $this->assertInstanceOf(Router::class, $router);

        $this->assertTrue(Router::hasMacro('image'));
        $route = $router->macroCall('image', ['media']);

        $this->assertInstanceOf(RoutingRoute::class, $route);
        $this->assertSame(['media/{path}', 'image'], [$route->uri(), $route->getName()]);
        $this->get('/media/'.substr(Image::for($this->photo)->preset('card')->url(), strlen('https://img.test/img/')))->assertOk();
    }

    public function test_image_routes_registers_the_same_route(): void
    {
        Image::fake();
        Image::routes();
        Route::getRoutes()->refreshNameLookups();

        $this->assertSame('img/{path}', Route::getRoutes()->getByName('image')?->uri());
        $this->get(Image::for($this->photo)->preset('card')->url())->assertOk();
    }
}
