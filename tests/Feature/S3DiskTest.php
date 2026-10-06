<?php

namespace Folklore\Image\Tests\Feature;

use Aws\S3\S3Client;
use Folklore\Image\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Uses the `filesystem` source on an S3-compatible disk (moto in CI, MinIO or any S3 API locally).
 *
 * Skipped unless IMAGE_TEST_S3_ENDPOINT is set, for example:
 * IMAGE_TEST_S3_ENDPOINT=http://127.0.0.1:9000 vendor/bin/phpunit tests/Feature/S3DiskTest.php
 */
class S3DiskTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => getenv('IMAGE_TEST_S3_KEY') ?: 'minioadmin',
            'secret' => getenv('IMAGE_TEST_S3_SECRET') ?: 'minioadmin',
            'region' => 'us-east-1',
            'bucket' => $this->bucket(),
            'endpoint' => getenv('IMAGE_TEST_S3_ENDPOINT') ?: null,
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);

        $app['config']->set('image.sources.s3', [
            'driver' => 'filesystem',
            'disk' => 's3',
            'path' => 'images',
            'cache' => false,
        ]);

        $app['config']->set('image.sources.s3_cached', [
            'driver' => 'filesystem',
            'disk' => 's3',
            'path' => 'images',
            'cache' => true,
            'cache_path' => $this->cachePath(),
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

    protected function setUp(): void
    {
        parent::setUp();

        if (! getenv('IMAGE_TEST_S3_ENDPOINT')) {
            $this->markTestSkipped('Set IMAGE_TEST_S3_ENDPOINT to run the S3-compatible disk tests.');
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => getenv('IMAGE_TEST_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => getenv('IMAGE_TEST_S3_KEY') ?: 'minioadmin',
                'secret' => getenv('IMAGE_TEST_S3_SECRET') ?: 'minioadmin',
            ],
        ]);
        if (! $client->doesBucketExist($this->bucket())) {
            $client->createBucket(['Bucket' => $this->bucket()]);
        }

        $disk = Storage::disk('s3');
        $disk->deleteDirectory('images');
        $disk->put('images/photo.jpg', file_get_contents(__DIR__.'/../fixture/image.jpg'));
        $disk->put('images/photo.png', file_get_contents(__DIR__.'/../fixture/image.png'));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->cachePath());

        parent::tearDown();
    }

    public function test_it_finds_images_and_their_format_on_the_disk()
    {
        $handler = app('image')->source('s3');

        $this->assertTrue($handler->getSource()->pathExists('photo.jpg'));
        $this->assertFalse($handler->getSource()->pathExists('missing.jpg'));
        $this->assertEquals('jpeg', $handler->format('photo.jpg'));
        $this->assertEquals('png', $handler->format('photo.png'));
    }

    public function test_it_makes_an_image_from_the_disk()
    {
        $image = app('image')->source('s3')->make('photo.jpg', [
            'width' => 100,
            'height' => 50,
            'crop' => true,
        ]);

        $this->assertEquals(100, $image->getSize()->getWidth());
        $this->assertEquals(50, $image->getSize()->getHeight());
    }

    public function test_it_saves_an_image_to_the_disk()
    {
        $handler = app('image')->source('s3');
        $image = $handler->make('photo.jpg', ['width' => 40, 'height' => 40, 'crop' => true]);

        $handler->save($image, 'derived/photo-small.jpg');

        $this->assertTrue(Storage::disk('s3')->exists('images/derived/photo-small.jpg'));
        $size = getimagesizefromstring(Storage::disk('s3')->get('images/derived/photo-small.jpg'));
        $this->assertEquals([40, 40], [$size[0], $size[1]]);
    }

    public function test_it_keeps_a_local_copy_when_the_source_cache_is_on()
    {
        app('image')->source('s3_cached')->make('photo.jpg', ['width' => 30, 'height' => 30, 'crop' => true]);

        $cachedFiles = (new Filesystem)->allFiles($this->cachePath());
        $this->assertCount(1, $cachedFiles);
        $this->assertEquals(
            file_get_contents(__DIR__.'/../fixture/image.jpg'),
            file_get_contents($cachedFiles[0]->getPathname())
        );
    }

    public function test_it_serves_an_image_from_the_disk_through_a_route()
    {
        $this->app['router']->image('s3/{pattern}', [
            'as' => 'image.s3',
            'source' => 's3',
            'cache' => false,
        ]);

        $response = $this->get('/s3/photo-filters(100x50-crop).jpg');

        $response->assertOk();
        $this->assertEquals('image/jpeg', $response->headers->get('Content-Type'));
        $size = getimagesizefromstring($this->content($response));
        $this->assertEquals([100, 50], [$size[0], $size[1]]);

        $this->get('/s3/missing-filters(100x50).jpg')->assertNotFound();
    }

    protected function bucket(): string
    {
        return getenv('IMAGE_TEST_S3_BUCKET') ?: 'laravel-image-tests';
    }

    protected function cachePath(): string
    {
        return __DIR__.'/../fixture/cache/s3';
    }

    protected function content($response): string
    {
        $baseResponse = $response->baseResponse;
        if (! $baseResponse instanceof StreamedResponse) {
            return $baseResponse->getContent();
        }

        ob_start();
        $baseResponse->sendContent();

        return ob_get_clean();
    }
}
