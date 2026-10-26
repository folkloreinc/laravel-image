<?php

declare(strict_types=1);

namespace Folklore\Image\Testing\Contracts;

use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Crop;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Ratio;
use Folklore\Image\Specs\Transform;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every Renderer has, for its own test case: the output has the size the spec
 * computes, and the format it asks for.
 *
 * @phpstan-require-extends TestCase
 */
trait RendererContractTests
{
    abstract protected function renderer(): Renderer;

    /**
     * A JPEG source in landscape, with its width and height, larger than 400×300.
     */
    abstract protected function landscapeSource(): ImageSource;

    /**
     * Whether the renderer writes real contents, whose type the test checks. A fake doesn't.
     */
    protected function rendersContents(): bool
    {
        return true;
    }

    /**
     * @return array<string, array{ImageSpec}>
     */
    public static function specs(): array
    {
        return [
            'nothing' => [new ImageSpec],
            'a width' => [new ImageSpec(width: 200)],
            'a height' => [new ImageSpec(height: 100)],
            'a box, cover' => [new ImageSpec(width: 200, height: 200)],
            'a box, inside' => [new ImageSpec(width: 200, height: 200, fit: Fit::Inside)],
            'a box, contain' => [new ImageSpec(width: 200, height: 200, fit: Fit::Contain)],
            'a box, fill' => [new ImageSpec(width: 200, height: 50, fit: Fit::Fill)],
            'a width and a ratio' => [new ImageSpec(width: 300, ratio: new Ratio(16, 9))],
            'a ratio' => [new ImageSpec(ratio: new Ratio(1, 1))],
            'larger than the source' => [new ImageSpec(width: 100_000)],
            'a crop' => [new ImageSpec(transform: new Transform(crop: new Crop(10, 20, 120, 80)))],
            'a rotation' => [new ImageSpec(transform: new Transform(rotate: 90), width: 100)],
        ];
    }

    #[DataProvider('specs')]
    public function test_the_output_has_the_size_of_the_spec(ImageSpec $spec): void
    {
        $source = $this->landscapeSource();
        $sourceSize = $source->size();
        $this->assertNotNull($sourceSize, 'The landscape source has its dimensions.');

        $image = $this->renderer()->render($source, $spec);

        $this->assertEquals($spec->outputSize($sourceSize), $image->size());
    }

    /**
     * @return array<string, array{Format, Format}>
     */
    public static function formats(): array
    {
        return [
            'auto, from the source' => [Format::Auto, Format::Jpeg],
            'jpeg' => [Format::Jpeg, Format::Jpeg],
            'png' => [Format::Png, Format::Png],
            'webp' => [Format::Webp, Format::Webp],
        ];
    }

    #[DataProvider('formats')]
    public function test_the_output_has_the_format_of_the_spec(Format $format, Format $expected): void
    {
        $image = $this->renderer()->render($this->landscapeSource(), new ImageSpec(width: 100, format: $format));

        $this->assertSame($expected, $image->format);
        $this->assertSame($expected->mime(), $image->mime());

        if ($this->rendersContents()) {
            $info = getimagesizefromstring($image->contents);
            $this->assertNotFalse($info, 'The contents are an image.');
            $this->assertSame($expected->mime(), $info['mime']);
            $this->assertSame([$image->width, $image->height], [$info[0], $info[1]]);
        }
    }

    public function test_a_crop_past_the_edges_of_the_source_is_clamped_to_it(): void
    {
        $source = $this->landscapeSource();
        [$width, $height] = [(int) $source->width, (int) $source->height];

        $image = $this->renderer()->render($source, new ImageSpec(transform: new Transform(crop: new Crop($width - 100, $height - 50, 400, 400)), format: Format::Png));

        $this->assertSame([100, 50], [$image->width, $image->height]);
    }

    public function test_a_crop_wholly_outside_the_source_leaves_the_whole_source(): void
    {
        $source = $this->landscapeSource();

        $image = $this->renderer()->render($source, new ImageSpec(transform: new Transform(crop: new Crop((int) $source->width, 0, 10, 10)), format: Format::Png));

        $this->assertSame([$source->width, $source->height], [$image->width, $image->height]);
    }
}
