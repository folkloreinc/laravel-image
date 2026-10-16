<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\S3;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Sources\DiskResolver;
use Folklore\Image\Testing\Contracts\SourceResolverContractTests;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use PHPUnit\Framework\Attributes\Group;

/**
 * The `disk` resolver on an S3-compatible disk: its version comes from the ETag, and its
 * metadata from the start of the file.
 */
#[Group('s3')]
final class S3DiskResolverTest extends TestCase
{
    use SourceResolverContractTests;
    use UsesS3Disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpS3();

        foreach (['landscape.jpg', 'image.png', 'orientation/orientation-6.jpg'] as $file) {
            $this->s3->put("originals/{$file}", (string) file_get_contents(self::fixturePath("images/{$file}")));
        }
    }

    protected function resolver(int $versionTtl = 300): SourceResolver
    {
        $filesystems = $this->app?->make(FilesystemFactory::class);
        $cache = $this->app?->make(Cache::class);
        $this->assertInstanceOf(FilesystemFactory::class, $filesystems);
        $this->assertInstanceOf(Cache::class, $cache);

        return new DiskResolver($filesystems, $cache, 'disk', 's3', 'originals', $versionTtl);
    }

    protected function reference(): mixed
    {
        return 'landscape.jpg';
    }

    public function test_sources_on_s3_have_their_metadata(): void
    {
        $jpeg = $this->resolver()->resolve('landscape.jpg');
        $png = $this->resolver()->resolve('image.png');
        $rotated = $this->resolver()->resolve('orientation/orientation-6.jpg');

        $this->assertSame(['s3', 'originals/landscape.jpg'], [$jpeg->disk, $jpeg->path]);
        $this->assertSame([800, 500, 'image/jpeg'], [$jpeg->width, $jpeg->height, $jpeg->mime]);
        $this->assertSame([100, 100, 'image/png'], [$png->width, $png->height, $png->mime]);
        $this->assertSame([80, 40, 6], [$rotated->width, $rotated->height, $rotated->orientation]);
    }

    public function test_the_version_comes_from_the_etag(): void
    {
        $before = $this->resolver(versionTtl: 0)->resolve('landscape.jpg');

        // The same contents give the same ETag, whenever they are written.
        $this->s3->put('originals/landscape.jpg', (string) file_get_contents(self::fixturePath('images/landscape.jpg')));
        $same = $this->resolver(versionTtl: 0)->resolve('landscape.jpg');

        $this->s3->put('originals/landscape.jpg', (string) file_get_contents(self::fixturePath('images/image.png')));
        $after = $this->resolver(versionTtl: 0)->resolve('landscape.jpg');

        $this->assertSame($before->version, $same->version);
        $this->assertNotSame($before->version, $after->version);
        $this->assertSame([100, 100], [$after->width, $after->height]);
    }
}
