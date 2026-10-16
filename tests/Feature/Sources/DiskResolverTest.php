<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Sources;

use Folklore\Image\Exceptions\InvalidImageSource;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Sources\DiskResolver;
use Folklore\Image\Testing\Contracts\SourceResolverContractTests;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * The `disk` resolver on a local disk. The S3-compatible disk is tested in S3DiskTest.
 */
final class DiskResolverTest extends TestCase
{
    use SourceResolverContractTests;

    private Filesystem $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->disk = Storage::fake('originals');
        foreach (['landscape.jpg', 'image.png', 'orientation/orientation-6.jpg', 'heic/photo.heic', 'svg/shape.svg'] as $file) {
            $this->disk->put("photos/{$file}", (string) file_get_contents(self::fixturePath("images/{$file}")));
        }
        $this->disk->put('secret.jpg', (string) file_get_contents(self::fixturePath('images/image.jpg')));
    }

    protected function resolver(string $root = 'photos', int $versionTtl = 300): DiskResolver
    {
        return new DiskResolver($this->filesystems(), $this->cache(), 'disk', 'originals', $root, $versionTtl);
    }

    protected function reference(): mixed
    {
        return 'landscape.jpg';
    }

    public function test_the_key_is_the_path_under_the_root(): void
    {
        $source = $this->resolver()->resolve('landscape.jpg');

        $this->assertSame('disk', $source->resolver);
        $this->assertSame('landscape.jpg', $source->key);
        $this->assertSame('originals', $source->disk);
        $this->assertSame('photos/landscape.jpg', $source->path);
        $this->assertFalse($source->isPrivate());
    }

    public function test_references_are_normalized_inside_the_root(): void
    {
        foreach (['/landscape.jpg', './landscape.jpg', 'orientation/../landscape.jpg', 'disk://landscape.jpg', '//landscape.jpg'] as $reference) {
            $this->assertSame('landscape.jpg', $this->resolver()->resolve($reference)->key, $reference);
        }
    }

    public function test_references_cannot_leave_the_root(): void
    {
        foreach (['../secret.jpg', 'orientation/../../secret.jpg', 'disk://../secret.jpg', "landscape.jpg\0.png", '..'] as $reference) {
            try {
                $this->resolver()->resolve($reference);
                $this->fail("The reference {$reference} was resolved.");
            } catch (SourceNotFound) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('secret.jpg', $this->resolver(root: '')->resolve('secret.jpg')->path);
    }

    public function test_keys_must_be_canonical(): void
    {
        foreach (['./landscape.jpg', 'orientation/../landscape.jpg', '/landscape.jpg', 'orientation\\..\\landscape.jpg'] as $key) {
            try {
                $this->resolver()->resolveKey($key);
                $this->fail("The key {$key} was resolved.");
            } catch (SourceNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_it_supports_paths_and_its_own_urls_only(): void
    {
        $resolver = $this->resolver();

        $this->assertTrue($resolver->supports('uploads/photo.jpg'));
        $this->assertTrue($resolver->supports('disk://uploads/photo.jpg'));
        foreach (['', 'media://med_01JAB3QX', 'https://example.com/photo.jpg', 's3://photo.jpg', 42, null, ['photo.jpg']] as $reference) {
            $this->assertFalse($resolver->supports($reference), var_export($reference, true));
        }

        $this->expectException(UnsupportedReference::class);
        $resolver->resolve('media://med_01JAB3QX');
    }

    public function test_another_name_resolves_its_own_urls(): void
    {
        $resolver = new DiskResolver($this->filesystems(), $this->cache(), 'archive', 'originals', 'photos');

        $this->assertSame('archive', $resolver->name());
        $this->assertTrue($resolver->supports('archive://landscape.jpg'));
        $this->assertFalse($resolver->supports('disk://landscape.jpg'));
        $this->assertSame('archive', $resolver->resolve('archive://landscape.jpg')->resolver);
    }

    public function test_an_invalid_name_is_refused(): void
    {
        $this->expectException(InvalidImageSource::class);

        new DiskResolver($this->filesystems(), $this->cache(), 'Disk');
    }

    public function test_sources_have_their_metadata(): void
    {
        $jpeg = $this->resolver()->resolve('landscape.jpg');
        $png = $this->resolver()->resolve('image.png');

        $this->assertSame([800, 500, 'image/jpeg', null], [$jpeg->width, $jpeg->height, $jpeg->mime, $jpeg->orientation]);
        $this->assertSame([100, 100, 'image/png'], [$png->width, $png->height, $png->mime]);
    }

    public function test_the_dimensions_are_those_of_the_upright_image(): void
    {
        // Stored as 40×80, with the orientation 6: upright, it is 80×40.
        $source = $this->resolver()->resolve('orientation/orientation-6.jpg');

        $this->assertSame([80, 40, 6], [$source->width, $source->height, $source->orientation]);
    }

    public function test_heic_and_svg_sources_have_their_mime_type(): void
    {
        $heic = $this->resolver()->resolve('heic/photo.heic');
        $svg = $this->resolver()->resolve('svg/shape.svg');

        // PHP 8.5 reads the dimensions of HEIF and SVG files, and names the type of HEIF ones image/heif.
        $this->assertContains($heic->mime, ['image/heic', 'image/heif']);
        $this->assertContains([$heic->width, $heic->height], [[null, null], [120, 80]]);
        $this->assertSame('image/svg+xml', $svg->mime);
        $this->assertContains([$svg->width, $svg->height], [[null, null], [200, 100]]);
    }

    public function test_the_version_changes_with_the_file(): void
    {
        $before = $this->resolver(versionTtl: 0)->resolve('landscape.jpg');
        $this->assertNotNull($before->version);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $before->version);

        $this->replace('photos/landscape.jpg', 'image.png', time() + 10);
        $after = $this->resolver(versionTtl: 0)->resolve('landscape.jpg');

        $this->assertNotSame($before->version, $after->version);
        $this->assertSame([100, 100, 'image/png'], [$after->width, $after->height, $after->mime]);
    }

    public function test_the_version_is_cached(): void
    {
        $before = $this->resolver()->resolve('landscape.jpg');

        $this->replace('photos/landscape.jpg', 'image.png', time() + 10);

        $this->assertEquals($before, $this->resolver()->resolve('landscape.jpg'));
    }

    public function test_the_metadata_is_read_once_per_version(): void
    {
        $before = $this->resolver(versionTtl: 0)->resolve('landscape.jpg');
        $modified = $this->disk->lastModified('photos/landscape.jpg');

        // Another file with the same modification time has the same version: its metadata
        // comes from the cache.
        $this->replace('photos/landscape.jpg', 'image.png', $modified);

        $this->assertEquals($before, $this->resolver(versionTtl: 0)->resolve('landscape.jpg'));
    }

    public function test_a_missing_file_is_not_found_and_not_cached(): void
    {
        try {
            $this->resolver()->resolve('later.jpg');
            $this->fail('A missing file was resolved.');
        } catch (SourceNotFound) {
            $this->addToAssertionCount(1);
        }

        $this->disk->put('photos/later.jpg', (string) file_get_contents(self::fixturePath('images/image.jpg')));

        $this->assertSame(300, $this->resolver()->resolve('later.jpg')->width);
    }

    public function test_the_disk_resolver_is_bound(): void
    {
        $resolver = $this->app?->make(DiskResolver::class);

        $this->assertInstanceOf(DiskResolver::class, $resolver);
        $this->assertSame('disk', $resolver->name());
    }

    private function replace(string $path, string $fixture, int $modified): void
    {
        $this->disk->put($path, (string) file_get_contents(self::fixturePath("images/{$fixture}")));
        touch($this->disk->path($path), $modified);
        clearstatcache();
    }

    private function filesystems(): FilesystemFactory
    {
        $filesystems = $this->app?->make(FilesystemFactory::class);
        $this->assertInstanceOf(FilesystemFactory::class, $filesystems);

        return $filesystems;
    }

    private function cache(): Cache
    {
        $cache = $this->app?->make(Cache::class);
        $this->assertInstanceOf(Cache::class, $cache);

        return $cache;
    }
}
