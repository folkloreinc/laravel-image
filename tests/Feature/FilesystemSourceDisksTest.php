<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\Mocks\RemoteDiskAdapter;
use Folklore\Image\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * The `filesystem` source on local and remote disks, with each source cache mode.
 *
 * The `remote` disk wraps a local adapter (see RemoteDiskAdapter), so it takes the
 * code paths of disks such as S3 without a server.
 */
class FilesystemSourceDisksTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        // A persistent cache store, so cached contents are serialized like in production
        $app['config']->set('cache.default', 'file');
        $app['config']->set('cache.stores.file.path', $this->cachePath().'/store');

        $app['config']->set('filesystems.disks.remote', [
            'driver' => 'remote-test',
            'root' => public_path('filesystem'),
        ]);

        $sources = [
            'remote' => ['disk' => 'remote', 'cache' => false],
            'remote_cache_store' => ['disk' => 'remote', 'cache' => true, 'cache_path' => null],
            'remote_cache_path' => ['disk' => 'remote', 'cache' => true, 'cache_path' => $this->cachePath().'/files'],
            'local_cached' => ['disk' => 'local', 'cache' => true, 'cache_path' => $this->cachePath().'/files'],
        ];
        foreach ($sources as $name => $config) {
            $app['config']->set('image.sources.'.$name, array_merge([
                'driver' => 'filesystem',
                'path' => null,
            ], $config));
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::extend('remote-test', function ($app, $config) {
            $adapter = new RemoteDiskAdapter(new LocalFilesystemAdapter($config['root']));

            return new FilesystemAdapter(new Flysystem($adapter, $config), $adapter, $config);
        });

        (new Filesystem)->ensureDirectoryExists(public_path('filesystem'));
        copy(public_path('image.jpg'), public_path('filesystem/disks-test.jpg'));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->cachePath());
        foreach (['disks-test.jpg', 'disks-saved.jpg'] as $file) {
            @unlink(public_path('filesystem/'.$file));
        }

        parent::tearDown();
    }

    public function test_every_source_reads_the_format_and_opens_the_image_twice()
    {
        foreach (['remote', 'remote_cache_store', 'remote_cache_path', 'local_cached'] as $name) {
            $source = app('image')->source($name);

            // The second time reads from the source cache, when there is one
            foreach ([1, 2] as $time) {
                $this->assertEquals('jpeg', $source->format('disks-test.jpg'), $name.', time '.$time);

                $image = $source->make('disks-test.jpg', ['width' => 30, 'height' => 30, 'crop' => true]);
                $this->assertEquals(30, $image->getSize()->getWidth(), $name.', time '.$time);
            }
        }
    }

    public function test_remote_disks_keep_a_copy_in_the_source_cache()
    {
        app('image')->source('remote_cache_path')->make('disks-test.jpg');
        $files = (new Filesystem)->allFiles($this->cachePath().'/files');
        $this->assertCount(1, $files);
        $this->assertEquals(file_get_contents(public_path('image.jpg')), file_get_contents($files[0]->getPathname()));

        // Without a cache path, the contents go to the cache store: once cached, a
        // change on the disk isn't read again
        app('image')->source('remote_cache_store')->make('disks-test.jpg');
        copy(public_path('image_small.jpg'), public_path('filesystem/disks-test.jpg'));
        $image = app('image')->source('remote_cache_store')->make('disks-test.jpg');
        $this->assertEquals(300, $image->getSize()->getWidth());
    }

    public function test_local_disks_are_read_in_place()
    {
        app('image')->source('local_cached')->make('disks-test.jpg');

        $this->assertDirectoryDoesNotExist($this->cachePath().'/files');
    }

    public function test_every_source_saves_an_image()
    {
        foreach (['remote', 'local_cached'] as $name) {
            $image = app('image')->source($name)->make('disks-test.jpg', ['width' => 20, 'height' => 20, 'crop' => true]);
            app('image')->source($name)->save($image, 'disks-saved.jpg');

            $this->assertEquals([20, 20], array_slice(getimagesize(public_path('filesystem/disks-saved.jpg')), 0, 2), $name);
            unlink(public_path('filesystem/disks-saved.jpg'));
        }
    }

    protected function cachePath(): string
    {
        return __DIR__.'/../fixture/cache/disks';
    }
}
