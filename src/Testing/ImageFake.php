<?php

declare(strict_types=1);

namespace Folklore\Image\Testing;

use Closure;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\ImageManager;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Urls\ImageUrl;
use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert;

/**
 * What Image::fake() returns: URLs and variants are built as usual, from the sources'
 * metadata, but nothing is rendered and no image driver is needed.
 *
 *     Image::fake();
 *     $this->get('/articles/1')->assertOk();
 *     Image::assertUrlGenerated(preset: 'card');
 *     Image::assertRendered(fn (ImageSpec $spec) => $spec->width === 800);
 */
class ImageFake extends ImageManager
{
    private readonly FakeRenderer $renderer;

    private readonly RecordingUrlCodec $codec;

    public function __construct(Container $container)
    {
        parent::__construct($container);

        $this->renderer = new FakeRenderer;
        $this->codec = new RecordingUrlCodec($container->make(UrlCodec::class));

        $container->instance(Renderer::class, $this->renderer);
        $container->instance(UrlCodec::class, $this->codec);
    }

    /**
     * Asserts that a URL was generated, for a preset when one is given, and for which the
     * callback returns true.
     *
     * @param  (Closure(ImageUrl): bool)|null  $callback
     */
    public function assertUrlGenerated(?string $preset = null, ?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            $this->generatedUrls($preset, $callback),
            $preset === null ? 'No matching image URL was generated.' : "No matching image URL was generated for the {$preset} preset.",
        );
    }

    /**
     * @param  (Closure(ImageUrl): bool)|null  $callback
     */
    public function assertUrlNotGenerated(?string $preset = null, ?Closure $callback = null): void
    {
        Assert::assertEmpty(
            $this->generatedUrls($preset, $callback),
            $preset === null ? 'An unexpected image URL was generated.' : "An unexpected image URL was generated for the {$preset} preset.",
        );
    }

    /**
     * Asserts that an image was rendered (in code, or by a request), for which the callback
     * returns true.
     *
     * @param  (Closure(ImageSpec, ImageSource): bool)|null  $callback
     */
    public function assertRendered(?Closure $callback = null): void
    {
        Assert::assertNotEmpty($this->renderedImages($callback), 'No matching image was rendered.');
    }

    /**
     * @param  (Closure(ImageSpec, ImageSource): bool)|null  $callback
     */
    public function assertNotRendered(?Closure $callback = null): void
    {
        Assert::assertEmpty($this->renderedImages($callback), 'An unexpected image was rendered.');
    }

    public function assertNothingRendered(): void
    {
        $this->assertNotRendered();
    }

    /**
     * The URLs generated, for a preset when one is given, and for which the callback returns true.
     *
     * @param  (Closure(ImageUrl): bool)|null  $callback
     * @return list<ImageUrl>
     */
    public function generatedUrls(?string $preset = null, ?Closure $callback = null): array
    {
        return array_values(array_filter(
            $this->codec->encoded(),
            fn (ImageUrl $url) => ($preset === null || $url->preset?->name === $preset) && ($callback === null || $callback($url)),
        ));
    }

    /**
     * The images rendered, for which the callback returns true.
     *
     * @param  (Closure(ImageSpec, ImageSource): bool)|null  $callback
     * @return list<array{source: ImageSource, spec: ImageSpec}>
     */
    public function renderedImages(?Closure $callback = null): array
    {
        return array_values(array_filter(
            $this->renderer->rendered(),
            fn (array $rendered) => $callback === null || $callback($rendered['spec'], $rendered['source']),
        ));
    }
}
