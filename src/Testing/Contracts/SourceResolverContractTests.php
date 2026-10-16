<?php

declare(strict_types=1);

namespace Folklore\Image\Testing\Contracts;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Sources\ImageSource;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every SourceResolver has, for its own test case:
 *
 *     final class MediaResolverTest extends TestCase
 *     {
 *         use SourceResolverContractTests;
 *
 *         protected function resolver(): SourceResolver { … }
 *         protected function reference(): mixed { … }       // an existing image
 *     }
 *
 * @phpstan-require-extends TestCase
 */
trait SourceResolverContractTests
{
    abstract protected function resolver(): SourceResolver;

    /**
     * A reference to an existing image, which the resolver supports.
     */
    abstract protected function reference(): mixed;

    /**
     * A reference the resolver doesn't support.
     */
    protected function unsupportedReference(): mixed
    {
        return new \stdClass;
    }

    /**
     * A key that points to no source.
     */
    protected function missingKey(): string
    {
        return 'missing/image-'.bin2hex(random_bytes(4)).'.jpg';
    }

    public function test_the_resolver_has_a_valid_name(): void
    {
        $this->assertTrue(ImageSource::isValidResolverName($this->resolver()->name()));
    }

    public function test_the_resolver_supports_its_references_only(): void
    {
        $this->assertTrue($this->resolver()->supports($this->reference()));
        $this->assertFalse($this->resolver()->supports($this->unsupportedReference()));
    }

    public function test_a_reference_resolves_to_a_source_of_this_resolver(): void
    {
        $source = $this->resolver()->resolve($this->reference());

        $this->assertSame($this->resolver()->name(), $source->resolver);
    }

    public function test_the_key_of_a_source_resolves_back_to_the_same_source(): void
    {
        $source = $this->resolver()->resolve($this->reference());

        $this->assertEquals($source, $this->resolver()->resolveKey($source->key));
    }

    public function test_a_source_resolves_the_same_way_twice(): void
    {
        $this->assertEquals($this->resolver()->resolve($this->reference()), $this->resolver()->resolve($this->reference()));
    }

    public function test_an_unsupported_reference_is_refused(): void
    {
        $this->expectException(UnsupportedReference::class);

        $this->resolver()->resolve($this->unsupportedReference());
    }

    public function test_a_missing_key_is_not_found(): void
    {
        $this->expectException(SourceNotFound::class);

        $this->resolver()->resolveKey($this->missingKey());
    }

    public function test_keys_that_leave_the_source_are_not_found(): void
    {
        foreach (['../secret.jpg', 'a/../../secret.jpg', '/etc/passwd', 'a//b.jpg', "a\0.jpg"] as $key) {
            try {
                $this->resolver()->resolveKey($key);
                $this->fail("The key {$key} was resolved.");
            } catch (SourceNotFound) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
