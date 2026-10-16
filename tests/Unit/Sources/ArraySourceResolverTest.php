<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Sources;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Testing\Contracts\SourceResolverContractTests;
use Folklore\Image\Tests\Doubles\ArraySourceResolver;
use Folklore\Image\Tests\TestCase;

/**
 * Runs the resolver contract tests against a test double, so they are known to pass on a
 * correct resolver before the disk and media resolvers use them.
 */
final class ArraySourceResolverTest extends TestCase
{
    use SourceResolverContractTests;

    protected function resolver(): SourceResolver
    {
        return new ArraySourceResolver(new ImageSource('array', 'uploads/photo.jpg', 'v1', width: 1200, height: 800, mime: 'image/jpeg'));
    }

    protected function reference(): mixed
    {
        return 'array://uploads/photo.jpg';
    }
}
