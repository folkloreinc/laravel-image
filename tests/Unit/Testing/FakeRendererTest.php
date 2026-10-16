<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Testing;

use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Testing\Contracts\RendererContractTests;
use Folklore\Image\Testing\FakeRenderer;
use Folklore\Image\Tests\TestCase;

/**
 * The fake renderer follows the renderer contract, except that it writes no contents.
 */
final class FakeRendererTest extends TestCase
{
    use RendererContractTests;

    protected function renderer(): Renderer
    {
        return new FakeRenderer;
    }

    protected function landscapeSource(): ImageSource
    {
        return new ImageSource('disk', 'photo.jpg', width: 1200, height: 800, mime: 'image/jpeg');
    }

    protected function rendersContents(): bool
    {
        return false;
    }

    public function test_it_records_what_it_renders(): void
    {
        $renderer = new FakeRenderer;
        $source = $this->landscapeSource();

        $renderer->render($source, new ImageSpec(width: 300));

        $this->assertCount(1, $renderer->rendered());
        $this->assertSame($source, $renderer->rendered()[0]['source']);
        $this->assertSame('w300.auto', $renderer->rendered()[0]['spec']->key());
    }

    public function test_a_source_without_dimensions_takes_the_size_asked_for(): void
    {
        $image = (new FakeRenderer)->render(new ImageSource('disk', 'photo.jpg'), new ImageSpec(width: 300, height: 200));

        $this->assertSame([300, 200], [$image->width, $image->height]);
        $this->assertSame('', $image->contents);
    }
}
