<?php

namespace Folklore\Image\Tests\Unit;

use Folklore\Image\SourceManager;
use Folklore\Image\Sources\FilesystemSource;
use Folklore\Image\Sources\LocalSource;
use Folklore\Image\Tests\TestCase;

/**
 * @coversDefaultClass Folklore\Image\SourceManager
 */
class SourceManagerTest extends TestCase
{
    protected $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new SourceManager($this->app);
    }

    /**
     * Test getting the local driver
     *
     * @test
     *
     * @covers ::createDriver
     * @covers ::createLocalDriver
     */
    public function test_local_driver()
    {
        $driver = $this->manager->driver('local');
        $config = app('config')->get('image.sources.local');
        $this->assertEquals(new LocalSource(app('image.imagine'), app('image.url'), $config), $driver);
    }

    /**
     * Test getting the filesystem driver
     *
     * @test
     *
     * @covers ::createDriver
     * @covers ::createFilesystemDriver
     */
    public function test_filesystem_driver()
    {
        $driver = $this->manager->driver('filesystem');
        $config = app('config')->get('image.sources.filesystem');
        $this->assertEquals(new FilesystemSource(app('image.imagine'), app('image.url'), $config), $driver);
    }

    /**
     * Test getting a custom driver
     *
     * @test
     *
     * @covers ::createDriver
     */
    public function test_custom_driver()
    {
        $this->app['config']->set('image.sources.custom', [
            'driver' => 'custom',
        ]);
        $this->manager->extend('custom', function () {
            return 'custom';
        });
        $driver = $this->manager->driver('custom');
        $this->assertEquals('custom', $driver);
    }

    /**
     * Test get and set default driver
     *
     * @test
     *
     * @covers ::getDefaultDriver
     * @covers ::setDefaultDriver
     */
    public function test_get_default_driver()
    {
        $defaultDriver = $this->app['config']->get('image.source');
        $this->assertEquals($defaultDriver, $this->manager->getDefaultDriver());

        $driver = 'filesystem';
        $this->manager->setDefaultDriver($driver);
        $this->assertEquals($driver, $this->manager->getDefaultDriver());
    }
}
