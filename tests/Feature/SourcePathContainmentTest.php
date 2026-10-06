<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Exception\InvalidPathException;
use Folklore\Image\Tests\TestCase;

/**
 * A requested path never resolves outside the root of its source.
 */
class SourcePathContainmentTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // The local source's root is a subdirectory, so `../image.jpg` points to an
        // existing image outside of it.
        $app['config']->set('image.sources.contained_local', [
            'driver' => 'local',
            'path' => __DIR__.'/../fixture/filesystem',
        ]);

        // The filesystem disk's root holds `image.jpg`; the source only covers `nested/`.
        $app['config']->set('image.sources.contained_filesystem', [
            'driver' => 'filesystem',
            'disk' => 'local',
            'path' => 'nested',
            'cache' => false,
        ]);

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
        // Clean up if a regression lets a save escape the source root.
        @unlink(__DIR__.'/../fixture/escaped.jpg');
        @unlink(__DIR__.'/../fixture/filesystem/escaped.jpg');

        parent::tearDown();
    }

    public function test_the_local_source_rejects_paths_outside_its_root()
    {
        $source = app('image')->source('contained_local')->getSource();

        $this->assertTrue($source->pathExists('image.jpg'));
        $this->assertTrue($source->pathExists('sub/../image.jpg'));
        $this->assertFalse($source->pathExists('../image.jpg'));
        $this->assertFalse($source->pathExists('/../image.jpg'));
        $this->assertFalse($source->pathExists('sub/../../image.jpg'));
    }

    public function test_the_filesystem_source_rejects_paths_outside_its_root()
    {
        $source = app('image')->source('contained_filesystem')->getSource();

        $this->assertFalse($source->pathExists('../image.jpg'));
        $this->assertFalse($source->pathExists('a/../../image.jpg'));
    }

    public function test_routes_return_404_for_paths_outside_the_source_root()
    {
        $this->app['router']->image('contained/{pattern}', [
            'as' => 'image.contained',
            'source' => 'contained_local',
            'cache' => false,
        ]);

        $this->get('/contained/image-filters(10x10).jpg')->assertOk();
        $this->get('/contained/%2e%2e/image-filters(10x10).jpg')->assertNotFound();
        $this->get('/contained/..%2fimage-filters(10x10).jpg')->assertNotFound();
    }

    public function test_saving_outside_the_source_root_is_rejected()
    {
        $handler = app('image')->source('contained_local');
        $image = $handler->make('image.jpg', ['width' => 10]);

        $this->expectException(InvalidPathException::class);
        $handler->save($image, '../escaped.jpg');
    }

    public function test_saving_outside_the_filesystem_source_root_is_rejected()
    {
        $image = app('image')->source('contained_local')->make('image.jpg', ['width' => 10]);

        $this->expectException(InvalidPathException::class);
        app('image')->source('contained_filesystem')->save($image, '../escaped.jpg');
    }
}
