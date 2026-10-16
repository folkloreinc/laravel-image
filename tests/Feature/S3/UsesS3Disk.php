<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\S3;

use Aws\S3\S3Client;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * An `s3` disk on an S3-compatible server (moto in CI, MinIO or any S3 API locally), emptied
 * before each test. Skipped unless IMAGE_TEST_S3_ENDPOINT is set:
 *
 *     IMAGE_TEST_S3_ENDPOINT=http://127.0.0.1:9000 vendor/bin/phpunit --group s3
 *
 * IMAGE_TEST_S3_KEY and IMAGE_TEST_S3_SECRET default to `minioadmin`. Test classes that use
 * it are in the `s3` group, which CI runs against moto.
 */
trait UsesS3Disk
{
    private const string BUCKET = 'laravel-image-tests';

    protected Filesystem $s3;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.s3', [
            'driver' => 's3',
            'key' => self::s3Env('IMAGE_TEST_S3_KEY') ?? 'minioadmin',
            'secret' => self::s3Env('IMAGE_TEST_S3_SECRET') ?? 'minioadmin',
            'region' => 'us-east-1',
            'bucket' => self::BUCKET,
            'endpoint' => self::s3Env('IMAGE_TEST_S3_ENDPOINT'),
            'use_path_style_endpoint' => true,
            'throw' => true,
        ]);
    }

    protected function setUpS3(): void
    {
        $endpoint = self::s3Env('IMAGE_TEST_S3_ENDPOINT');
        if ($endpoint === null) {
            $this->markTestSkipped('Set IMAGE_TEST_S3_ENDPOINT to run the S3-compatible disk tests.');
        }

        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => self::s3Env('IMAGE_TEST_S3_KEY') ?? 'minioadmin',
                'secret' => self::s3Env('IMAGE_TEST_S3_SECRET') ?? 'minioadmin',
            ],
        ]);
        if (! $client->doesBucketExist(self::BUCKET)) {
            $client->createBucket(['Bucket' => self::BUCKET]);
        }

        $this->s3 = Storage::disk('s3');
        foreach ($this->s3->allFiles() as $file) {
            $this->s3->delete($file);
        }
    }

    private static function s3Env(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
