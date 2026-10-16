<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Storage;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\StoredDerivative;
use Folklore\Image\Tests\TestCase;

final class DerivativeKeyTest extends TestCase
{
    public function test_a_derivative_is_stored_under_its_source_version_and_canonical_spec(): void
    {
        $source = new ImageSource('media', 'med_01JAB3QX', '8c1f', mime: 'image/png');

        $this->assertSame('media/med_01JAB3QX/8c1f/w800,h450.webp', DerivativeKey::for($source, new ImageSpec(width: 800, height: 450, format: Format::Webp))->path());
        $this->assertSame('media/med_01JAB3QX/8c1f/_.png', DerivativeKey::for($source, new ImageSpec)->path(), 'The auto format is resolved from the source.');
        $this->assertSame('disk/uploads/photo.jpg/_/w300.jpg', DerivativeKey::for(new ImageSource('disk', 'uploads/photo.jpg'), new ImageSpec(width: 300))->path());
        $this->assertSame('media/med_01JAB3QX', DerivativeKey::directoryOf($source));
    }

    public function test_a_derivative_has_a_concrete_format(): void
    {
        $this->expectException(InvalidImageSpec::class);

        new DerivativeKey('disk', 'a.jpg', null, new ImageSpec);
    }

    public function test_a_stored_derivative_has_a_strong_etag_from_its_key(): void
    {
        $key = DerivativeKey::for(new ImageSource('disk', 'a.jpg'), new ImageSpec(width: 300));
        $open = fn () => fopen('php://memory', 'r') ?: throw new \RuntimeException('No memory stream.');

        $etag = (new StoredDerivative($key, 0, null, $open))->etag();

        $this->assertMatchesRegularExpression('/^"[0-9a-f]{32}"$/', $etag);
        $this->assertSame($etag, (new StoredDerivative($key, 10, 1767225600, $open))->etag());
    }
}
