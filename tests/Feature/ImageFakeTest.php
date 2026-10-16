<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Facades\Image;
use Folklore\Image\ImageManager;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Testing\FakeRenderer;
use Folklore\Image\Testing\ImageFake;
use Folklore\Image\Testing\InMemoryDerivativeStore;
use Folklore\Image\Tests\Doubles\ArraySourceResolver;
use Folklore\Image\Urls\ImageUrl;
use Folklore\Image\Urls\V2Codec;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Image::fake(), through the builder: URLs and variants stay real, nothing is rendered.
 */
final class ImageFakeTest extends ImageTestCase
{
    public function test_the_container_has_the_v2_codec_and_the_manager(): void
    {
        $this->assertInstanceOf(V2Codec::class, $this->app?->make(UrlCodec::class));
        $this->assertInstanceOf(ImageManager::class, Image::getFacadeRoot());
    }

    public function test_the_fake_replaces_the_renderer_the_store_and_the_manager(): void
    {
        $fake = Image::fake();

        $app = $this->app;
        $this->assertNotNull($app);
        $this->assertInstanceOf(ImageFake::class, Image::getFacadeRoot());
        $this->assertSame($fake, $app->make(ImageManager::class));
        $this->assertInstanceOf(FakeRenderer::class, $app->make(Renderer::class));
        $this->assertInstanceOf(InMemoryDerivativeStore::class, $app->make(DerivativeStore::class));
    }

    public function test_urls_stay_real_and_are_recorded(): void
    {
        Image::fake();
        Image::resolver(new ArraySourceResolver($this->photo));

        $url = Image::for($this->photo)->preset('card')->webp()->url();

        $this->assertStringStartsWith('https://img.test/img/_/card.', $url);
        Image::assertUrlGenerated();
        Image::assertUrlGenerated(preset: 'card');
        Image::assertUrlGenerated('card', fn (ImageUrl $url) => $url->format === Format::Webp);
        Image::assertUrlNotGenerated(preset: 'og');
        Image::assertUrlNotGenerated('card', fn (ImageUrl $url) => $url->isSigned());
    }

    public function test_variants_are_recorded_with_their_urls(): void
    {
        Image::fake();

        Image::for($this->photo)->variants('card');

        $this->assertCount(9, Image::generatedUrls('card'));
        Image::assertUrlGenerated('card', fn (ImageUrl $url) => $url->width === 400 && $url->format === Format::Avif);
    }

    public function test_url_assertions_fail_when_nothing_matches(): void
    {
        Image::fake();
        Image::for($this->photo)->width(300)->signed()->url();

        $this->assertAssertionFails(fn () => Image::assertUrlGenerated(preset: 'card'));
        $this->assertAssertionFails(fn () => Image::assertUrlNotGenerated());
    }

    public function test_renders_are_recorded_with_real_dimensions_and_no_contents(): void
    {
        Image::fake();
        Image::assertNothingRendered();

        $image = Image::for($this->photo)->size(800, 450)->avif()->render();

        $this->assertSame([800, 450, Format::Avif, ''], [$image->width, $image->height, $image->format, $image->contents]);
        Image::assertRendered();
        Image::assertRendered(fn (ImageSpec $spec) => $spec->width === 800);
        Image::assertRendered(fn (ImageSpec $spec, ImageSource $source) => $source->key === 'photos/2026/photo.jpg');
        Image::assertNotRendered(fn (ImageSpec $spec) => $spec->width === 400);
        $this->assertAssertionFails(fn () => Image::assertNothingRendered());
        $this->assertAssertionFails(fn () => Image::assertRendered(fn (ImageSpec $spec) => $spec->format === Format::Webp));
    }

    public function test_the_fake_follows_the_upscaling_policy(): void
    {
        $this->config()->set('image.upscale', true);
        Image::fake();

        $image = Image::for($this->photo)->width(2400)->render();

        $this->assertSame([2400, 1600], [$image->width, $image->height]);
    }

    private function assertAssertionFails(\Closure $assertion): void
    {
        try {
            $assertion();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The assertion passed.');
    }
}
