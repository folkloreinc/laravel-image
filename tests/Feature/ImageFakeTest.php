<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Facades\Image;
use Folklore\Image\ImageManager;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Testing\FakeRenderer;
use Folklore\Image\Testing\ImageFake;
use Folklore\Image\Tests\TestCase;
use Folklore\Image\Urls\ImageUrl;
use Folklore\Image\Urls\PresetReference;
use Folklore\Image\Urls\V2Codec;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The surface of Image::fake(). Until the builder lands, the tests call the contracts the
 * builder and the controller will call: the codec and the renderer from the container.
 */
final class ImageFakeTest extends TestCase
{
    private ImageSource $source;

    protected function setUp(): void
    {
        parent::setUp();

        $this->source = new ImageSource('media', 'med_01JAB3QX', '8c1f', width: 1200, height: 800, mime: 'image/jpeg');
    }

    public function test_the_container_has_the_v2_codec_and_the_manager(): void
    {
        $this->assertInstanceOf(V2Codec::class, $this->app?->make(UrlCodec::class));
        $this->assertInstanceOf(ImageManager::class, Image::getFacadeRoot());
    }

    public function test_the_fake_replaces_the_renderer_and_the_manager(): void
    {
        $fake = Image::fake();

        $app = $this->app;
        $this->assertNotNull($app);
        $this->assertInstanceOf(ImageFake::class, Image::getFacadeRoot());
        $this->assertSame($fake, $app->make(ImageManager::class));
        $this->assertInstanceOf(FakeRenderer::class, $app->make(Renderer::class));
    }

    public function test_urls_stay_real_and_are_recorded(): void
    {
        Image::fake();

        $path = $this->codec()->encode(ImageUrl::forSource($this->source, new PresetReference('card', '3f9a1c'), Format::Webp));

        $this->assertSame('_/card.3f9a1c,v-8c1f/media/med_01JAB3QX.webp', $path);
        Image::assertUrlGenerated();
        Image::assertUrlGenerated(preset: 'card');
        Image::assertUrlGenerated('card', fn (ImageUrl $url) => $url->format === Format::Webp);
        Image::assertUrlNotGenerated(preset: 'og');
        Image::assertUrlNotGenerated('card', fn (ImageUrl $url) => $url->isSigned());
    }

    public function test_url_assertions_fail_when_nothing_matches(): void
    {
        Image::fake();
        $this->codec()->encode(ImageUrl::forSource($this->source, new ImageSpec(width: 300)));

        $this->assertAssertionFails(fn () => Image::assertUrlGenerated(preset: 'card'));
        $this->assertAssertionFails(fn () => Image::assertUrlNotGenerated());
    }

    public function test_renders_are_recorded_with_real_dimensions_and_no_contents(): void
    {
        Image::fake();
        Image::assertNothingRendered();

        $image = $this->renderer()->render($this->source, new ImageSpec(width: 800, height: 450, format: Format::Avif));

        $this->assertSame([800, 450, Format::Avif, ''], [$image->width, $image->height, $image->format, $image->contents]);
        Image::assertRendered();
        Image::assertRendered(fn (ImageSpec $spec) => $spec->width === 800);
        Image::assertRendered(fn (ImageSpec $spec, ImageSource $source) => $source->key === 'med_01JAB3QX');
        Image::assertNotRendered(fn (ImageSpec $spec) => $spec->width === 400);
        $this->assertAssertionFails(fn () => Image::assertNothingRendered());
        $this->assertAssertionFails(fn () => Image::assertRendered(fn (ImageSpec $spec) => $spec->format === Format::Webp));
    }

    private function codec(): UrlCodec
    {
        $codec = $this->app?->make(UrlCodec::class);
        $this->assertInstanceOf(UrlCodec::class, $codec);

        return $codec;
    }

    private function renderer(): Renderer
    {
        $renderer = $this->app?->make(Renderer::class);
        $this->assertInstanceOf(Renderer::class, $renderer);

        return $renderer;
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
