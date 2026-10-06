<?php

namespace Folklore\Image\Tests;

use Folklore\Image\Facade;
use Folklore\Image\ServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    /**
     * Define environment setup.
     *
     * @param  Application  $app
     * @return void
     */
    protected function getEnvironmentSetUp($app)
    {
        if (method_exists($app, 'usePublicPath')) {
            $app->usePublicPath(__DIR__.'/fixture');
        } else {
            $app->instance('path.public', __DIR__.'/fixture');
        }

        $app['config']->set('image.source', 'local');
        $app['config']->set('image.sources', [
            'local' => [
                'driver' => 'local',
                'path' => public_path(),
                'ignore' => [
                    public_path('cache'),
                    public_path('filesystem'),
                    public_path('custom'),
                    public_path('metadata'),
                ],
            ],
            'filesystem' => [
                'driver' => 'filesystem',
                'disk' => 'local',
                'path' => null,
                'cache' => true,
                'cache_path' => public_path('cache'),
            ],
        ]);

        $app['config']->set('filesystems.disks.local.root', public_path('filesystem'));
    }

    protected function getPackageProviders($app)
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app)
    {
        return [
            'Image' => Facade::class,
        ];
    }
}
