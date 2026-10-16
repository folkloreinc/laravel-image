<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\S3;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\DiskStore;
use Folklore\Image\Testing\Contracts\DerivativeStoreContractTests;
use Folklore\Image\Tests\TestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * DiskStore on an S3-compatible disk, as on DigitalOcean Spaces.
 */
#[Group('s3')]
final class S3DiskStoreTest extends TestCase
{
    use DerivativeStoreContractTests;
    use UsesS3Disk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpS3();
    }

    protected function store(bool $public = false): DerivativeStore
    {
        return new DiskStore($this->s3, 'derivatives', $public);
    }

    public function test_derivatives_on_s3_have_their_type_and_caching_headers(): void
    {
        $key = DerivativeKey::for(new ImageSource('media', 'med_01JAB3QX', '8c1f02ab'), new ImageSpec(width: 800, format: Format::Webp));

        $this->store()->put($key, new RenderedImage('webp contents', Format::Webp, 800, 450));

        $path = 'derivatives/media/med_01JAB3QX/8c1f02ab/w800.webp';
        $this->assertSame('image/webp', $this->s3->mimeType($path));
        $this->assertSame(strlen('webp contents'), $this->store()->get($key)?->length);
    }

    public function test_an_s3_disk_gives_urls_when_public(): void
    {
        $key = DerivativeKey::for(new ImageSource('media', 'med_01JAB3QX', '8c1f02ab'), new ImageSpec(width: 800, format: Format::Webp));

        $this->assertNull($this->store()->url($key));
        $this->assertStringEndsWith('/derivatives/media/med_01JAB3QX/8c1f02ab/w800.webp', (string) $this->store(public: true)->url($key));
    }
}
