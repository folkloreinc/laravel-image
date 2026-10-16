<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ArrayPreset;
use Folklore\Image\Specs\Format;
use Folklore\Image\Tests\TestCase;

final class ArrayPresetTest extends TestCase
{
    private const array CARD = ['width' => 800, 'ratio' => '16:9', 'fit' => 'cover', 'widths' => [400, 800, 1200], 'formats' => ['avif', 'webp']];

    public function test_a_preset_has_a_spec_widths_and_formats(): void
    {
        $preset = ArrayPreset::fromArray('card', self::CARD);

        $this->assertSame('card', $preset->name());
        $this->assertSame('w800,ar-16-9.auto', $preset->spec(new ImageSource('disk', 'photo.jpg'))->key());
        $this->assertSame([400, 800, 1200], $preset->widths());
        $this->assertSame([Format::Avif, Format::Webp], $preset->formats());
    }

    public function test_the_version_is_a_short_hash_of_the_definition(): void
    {
        $version = ArrayPreset::fromArray('card', self::CARD)->version();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{6}$/', $version);
        $this->assertSame(substr(hash('sha256', 'w800,ar-16-9.auto|400,800,1200|avif,webp'), 0, 6), $version);
        $this->assertSame("card.{$version}", ArrayPreset::fromArray('card', self::CARD)->reference()->toString());
    }

    public function test_the_version_ignores_the_order_and_the_defaults(): void
    {
        $version = ArrayPreset::fromArray('card', self::CARD)->version();

        $this->assertSame($version, ArrayPreset::fromArray('card', [
            'formats' => ['avif', 'webp'],
            'widths' => [1200, 400, 800, 800],
            'ratio' => '32:18',
            'width' => 800,
        ])->version());
    }

    public function test_the_version_changes_with_the_definition(): void
    {
        $version = ArrayPreset::fromArray('card', self::CARD)->version();

        foreach ([
            ['width' => 900],
            ['ratio' => '4:3'],
            ['widths' => [400, 800]],
            ['formats' => ['webp', 'avif']],
            ['quality' => 70],
            ['format' => 'jpeg'],
        ] as $change) {
            $this->assertNotSame($version, ArrayPreset::fromArray('card', [...self::CARD, ...$change])->version(), (string) json_encode($change));
        }
    }

    public function test_an_invalid_preset_is_refused(): void
    {
        foreach ([
            ['Card', self::CARD],
            ['card', [...self::CARD, 'widths' => [0]]],
            ['card', [...self::CARD, 'formats' => ['auto']]],
            ['card', [...self::CARD, 'unknown' => true]],
        ] as [$name, $definition]) {
            try {
                ArrayPreset::fromArray($name, $definition);
                $this->fail("The {$name} preset was accepted.");
            } catch (InvalidImageSpec) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
