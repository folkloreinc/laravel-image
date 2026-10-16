<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Specs;

use Folklore\Image\Contracts\Preset;
use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ArrayPreset;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Container\Container;

final class PresetRegistryTest extends TestCase
{
    public function test_presets_come_from_the_config(): void
    {
        $registry = $this->registry(['card' => ['width' => 800, 'ratio' => '16:9'], 'square' => SquarePreset::class]);

        $this->assertSame(['card', 'square'], array_keys($registry->all()));
        $this->assertTrue($registry->has('card'));
        $this->assertFalse($registry->has('og'));
        $this->assertNull($registry->find('og'));
        $this->assertInstanceOf(ArrayPreset::class, $registry->get('card'));
        $this->assertInstanceOf(SquarePreset::class, $registry->get('square'));
        $this->assertSame('card.'.$registry->get('card')->version(), PresetRegistry::reference($registry->get('card'))->toString());
    }

    public function test_presets_registered_in_code_win(): void
    {
        $registry = $this->registry(['square' => ['width' => 100]]);
        $registry->register(new SquarePreset);

        $this->assertInstanceOf(SquarePreset::class, $registry->get('square'));
    }

    public function test_an_unknown_preset_is_refused(): void
    {
        $this->expectException(InvalidImageSpec::class);
        $this->registry([])->get('card');
    }

    public function test_an_invalid_preset_fails_with_its_name(): void
    {
        foreach ([['card' => ['width' => 'big']], ['card' => 'NotAClass'], ['card' => SquarePreset::class]] as $config) {
            try {
                $this->registry($config)->all();
                $this->fail('An invalid preset was accepted.');
            } catch (InvalidImageConfig $exception) {
                $this->assertStringContainsString('image.presets.card', $exception->getMessage());
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function registry(array $config): PresetRegistry
    {
        $container = $this->app?->make(Container::class);
        $this->assertInstanceOf(Container::class, $container);

        return new PresetRegistry($container, $config);
    }
}

final class SquarePreset implements Preset
{
    public function name(): string
    {
        return 'square';
    }

    public function version(): string
    {
        return '000001';
    }

    public function spec(ImageSource $source): ImageSpec
    {
        return ImageSpec::fromArray(['width' => 1080, 'ratio' => '1:1']);
    }

    public function widths(): array
    {
        return [];
    }

    public function formats(): array
    {
        return [Format::Webp];
    }
}
