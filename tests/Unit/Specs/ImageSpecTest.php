<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Ratio;
use Folklore\Image\Specs\Size;
use Folklore\Image\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs tests/fixtures/specs.json: canonical forms, keys and output sizes.
 */
final class ImageSpecTest extends TestCase
{
    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function validSpecs(): array
    {
        return self::cases('valid');
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    public static function invalidSpecs(): array
    {
        return self::cases('invalid');
    }

    /**
     * @param  array<mixed>  $case
     */
    #[DataProvider('validSpecs')]
    public function test_a_spec_has_a_canonical_form_and_key(array $case): void
    {
        $this->assertIsArray($case['options']);
        $spec = ImageSpec::fromArray($case['options']);

        $this->assertSame($case['canonical'], $spec->toArray());
        $this->assertSame($case['key'], $spec->key());
        $this->assertTrue($spec->equals(ImageSpec::fromArray($spec->toArray())), 'The canonical form gives the same spec.');
        $this->assertSame($spec->key(), ImageSpec::fromArray($spec->toArray())->key());
    }

    /**
     * @param  array<mixed>  $case
     */
    #[DataProvider('validSpecs')]
    public function test_a_spec_computes_its_output_size(array $case): void
    {
        $this->assertIsArray($case['options']);
        $this->assertIsArray($case['sizes']);
        $spec = ImageSpec::fromArray($case['options']);

        foreach ($case['sizes'] as $size) {
            $this->assertIsArray($size);
            [$sourceWidth, $sourceHeight] = $this->dimensions($size['source']);
            [$width, $height] = $this->dimensions($size['output']);
            $this->assertIsBool($size['upscale']);

            $output = $spec->outputSize(new Size($sourceWidth, $sourceHeight), $size['upscale']);

            $this->assertSame([$width, $height], [$output->width, $output->height], "{$sourceWidth}×{$sourceHeight}".($size['upscale'] ? ', upscaled' : ''));
        }
    }

    /**
     * @param  array<mixed>  $case
     */
    #[DataProvider('invalidSpecs')]
    public function test_an_invalid_spec_is_refused(array $case): void
    {
        $this->assertIsArray($case['options']);
        $this->expectException(InvalidImageSpec::class);

        ImageSpec::fromArray($case['options']);
    }

    public function test_a_crop_outside_the_source_is_clamped_or_leaves_the_whole_source(): void
    {
        $fixtures = self::jsonFixture('specs.json');
        $this->assertIsArray($fixtures['outside']);

        foreach ($fixtures['outside'] as $case) {
            $this->assertIsArray($case);
            $this->assertIsArray($case['options']);
            $this->assertIsString($case['description']);
            [$width, $height] = $this->dimensions($case['source']);
            [$outputWidth, $outputHeight] = $this->dimensions($case['output']);

            $output = ImageSpec::fromArray($case['options'])->outputSize(new Size($width, $height), upscale: true);

            $this->assertSame([$outputWidth, $outputHeight], [$output->width, $output->height], $case['description']);
        }
    }

    public function test_the_constructor_and_the_array_form_give_the_same_spec(): void
    {
        $spec = new ImageSpec(width: 800, ratio: new Ratio(16, 9), focus: new Focus(0.3, 0.6), operations: ['grayscale', 'blur' => [5]], format: Format::Webp);

        $this->assertSame('w800,ar-16-9,fp-30-60,blur-5,grayscale.webp', $spec->key());
        $this->assertTrue($spec->equals(ImageSpec::fromArray([
            'operations' => ['blur' => 5, 'grayscale'],
            'format' => 'webp',
            'focus' => ['x' => 0.3, 'y' => 0.6],
            'ratio' => '16:9',
            'width' => 800,
        ])));
    }

    public function test_with_changes_or_removes_options(): void
    {
        $spec = new ImageSpec(width: 800, height: 600, fit: Fit::Contain);

        $this->assertSame('w400,h600,fit-contain.auto', $spec->with(['width' => 400])->key());
        $this->assertSame('w800.auto', $spec->with(['height' => null])->key());
        $this->assertSame('w800,h600,fit-contain.avif', $spec->withFormat(Format::Avif)->key());
        $this->assertSame('w800,h600,fit-contain.auto', $spec->key(), 'The spec is immutable.');
    }

    public function test_at_width_keeps_the_shape(): void
    {
        $this->assertSame('w400,h210.jpeg', (new ImageSpec(width: 1200, height: 630, format: Format::Jpeg))->atWidth(400)->key());
        $this->assertSame('w400,h300,fit-contain.auto', (new ImageSpec(width: 800, height: 600, fit: Fit::Contain))->atWidth(400)->key());
        $this->assertSame('w400,ar-16-9.auto', (new ImageSpec(width: 800, ratio: new Ratio(16, 9)))->atWidth(400)->key());
        $this->assertSame('w400,ar-16-9.auto', (new ImageSpec(height: 450, ratio: new Ratio(16, 9)))->atWidth(400)->key());
        $this->assertSame('w400.auto', (new ImageSpec(height: 450))->atWidth(400)->key());
        $this->assertSame('w400,q70.auto', (new ImageSpec(quality: 70))->atWidth(400)->key());
    }

    public function test_specs_are_compared_by_key(): void
    {
        $this->assertTrue((new ImageSpec(width: 300, fit: Fit::Inside))->equals(new ImageSpec(width: 300)));
        $this->assertFalse((new ImageSpec(width: 300))->equals(new ImageSpec(width: 300, format: Format::Webp)));
    }

    /**
     * @return array<string, array{array<mixed>}>
     */
    private static function cases(string $group): array
    {
        $fixtures = self::jsonFixture('specs.json');
        self::assertIsArray($fixtures[$group]);

        $cases = [];
        foreach ($fixtures[$group] as $case) {
            self::assertIsArray($case);
            self::assertIsString($case['description']);
            $cases[$case['description']] = [$case];
        }

        return $cases;
    }

    /**
     * @return array{int, int}
     */
    private function dimensions(mixed $size): array
    {
        $this->assertIsArray($size);
        [$width, $height] = $size;
        $this->assertIsInt($width);
        $this->assertIsInt($height);

        return [$width, $height];
    }
}
