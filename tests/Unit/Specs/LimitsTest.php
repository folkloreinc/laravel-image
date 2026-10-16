<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Limits;
use Folklore\Image\Specs\Size;
use Folklore\Image\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class LimitsTest extends TestCase
{
    /**
     * @return array<string, array{ImageSpec, Size|null, bool}>
     */
    public static function cases(): array
    {
        return [
            'a width at the limit' => [new ImageSpec(width: 1000), null, true],
            'a width over the limit' => [new ImageSpec(width: 1001), null, false],
            'a height at the limit' => [new ImageSpec(height: 800), null, true],
            'a height over the limit' => [new ImageSpec(height: 801), null, false],
            'a box at the pixel limit' => [new ImageSpec(width: 1000, height: 500), null, true],
            'a box over the pixel limit' => [new ImageSpec(width: 1000, height: 501), null, false],
            'an output at the limits' => [new ImageSpec(format: Format::Webp), new Size(1000, 500), true],
            'an output too wide' => [new ImageSpec, new Size(1001, 10), false],
            'an output too tall' => [new ImageSpec, new Size(10, 801), false],
            'an output over the pixel limit' => [new ImageSpec, new Size(1000, 501), false],
            'operations at the limit' => [new ImageSpec(operations: ['blur', 'grayscale']), null, true],
            'operations over the limit' => [new ImageSpec(operations: ['blur', 'grayscale', 'sharpen']), null, false],
        ];
    }

    #[DataProvider('cases')]
    public function test_limits_are_checked(ImageSpec $spec, ?Size $output, bool $allowed): void
    {
        $limits = new Limits(maxWidth: 1000, maxHeight: 800, maxPixels: 500_000, maxOperations: 2);

        if (! $allowed) {
            $this->expectException(InvalidImageSpec::class);
        }

        $limits->check($spec, $output);
        $this->addToAssertionCount(1);
    }

    public function test_null_limits_allow_anything(): void
    {
        (new Limits)->check(new ImageSpec(width: 100_000, height: 100_000, operations: ['blur', 'grayscale', 'sharpen', 'trim']), new Size(100_000, 100_000));

        $this->addToAssertionCount(1);
    }
}
