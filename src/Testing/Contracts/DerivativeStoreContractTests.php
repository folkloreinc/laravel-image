<?php

declare(strict_types=1);

namespace Folklore\Image\Testing\Contracts;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\PresetSpecKey;
use Folklore\Image\Urls\PresetReference;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour every DerivativeStore that keeps derivatives has, for its own test case.
 * NullStore, which keeps nothing, doesn't use it.
 *
 * @phpstan-require-extends TestCase
 */
trait DerivativeStoreContractTests
{
    /**
     * An empty store.
     */
    abstract protected function store(): DerivativeStore;

    private function derivativeKey(string $key = 'uploads/photo.jpg', ?string $version = 'v1', int $width = 200): DerivativeKey
    {
        return DerivativeKey::for(
            new ImageSource('disk', $key, $version, mime: 'image/jpeg'),
            new ImageSpec(width: $width, format: Format::Webp),
        );
    }

    private function renderedImage(string $contents = 'contents'): RenderedImage
    {
        return new RenderedImage($contents, Format::Webp, 200, 150);
    }

    public function test_a_missing_derivative_is_null(): void
    {
        $this->assertNull($this->store()->get($this->derivativeKey()));
    }

    public function test_a_stored_derivative_is_read_back(): void
    {
        $store = $this->store();
        $key = $this->derivativeKey();

        $stored = $store->put($key, $this->renderedImage('webp contents'));
        $read = $store->get($key);

        $this->assertNotNull($read);
        foreach ([$stored, $read] as $derivative) {
            $this->assertSame($key->path(), $derivative->key->path());
            $this->assertSame('webp contents', $derivative->contents());
            $this->assertSame(strlen('webp contents'), $derivative->length);
            $this->assertSame('image/webp', $derivative->mime());
        }
    }

    public function test_a_put_replaces_the_derivative(): void
    {
        $store = $this->store();
        $key = $this->derivativeKey();

        $store->put($key, $this->renderedImage('first'));
        $store->put($key, $this->renderedImage('second'));

        $this->assertSame('second', $store->get($key)?->contents());
    }

    public function test_clearing_a_source_deletes_all_its_derivatives_and_only_them(): void
    {
        $store = $this->store();
        $ofSource = [$this->derivativeKey(), $this->derivativeKey(width: 400), $this->derivativeKey(version: 'v2'), $this->derivativeKey(version: null)];
        $ofOthers = [$this->derivativeKey('uploads/photo.jpg.webp'), $this->derivativeKey('uploads/photo'), $this->derivativeKey('uploads/other.jpg')];
        foreach ([...$ofSource, ...$ofOthers] as $key) {
            $store->put($key, $this->renderedImage());
        }

        $deleted = $store->clear(new ImageSource('disk', 'uploads/photo.jpg'));

        $this->assertSame(count($ofSource), $deleted);
        foreach ($ofSource as $key) {
            $this->assertNull($store->get($key), $key->path());
        }
        foreach ($ofOthers as $key) {
            $this->assertNotNull($store->get($key), $key->path());
        }
    }

    public function test_a_preset_spec_is_recorded_per_preset_version_and_source_version(): void
    {
        $store = $this->store();
        $source = new ImageSource('disk', 'uploads/photo.jpg', 'v1', mime: 'image/jpeg');
        $key = PresetSpecKey::for($source, new PresetReference('card', '3f9a1c'));
        $spec = ImageSpec::fromArray(['width' => 800, 'ratio' => '16:9', 'focus' => [0.3, 0.6], 'operations' => ['blur' => 5], 'quality' => 70]);

        $this->assertNull($store->presetSpec($key));
        $store->putPresetSpec($key, $spec);

        $this->assertSame($spec->key(), $store->presetSpec($key)?->key());
        $this->assertNull($store->presetSpec(PresetSpecKey::for($source, new PresetReference('card', '000000'))));
        $this->assertNull($store->presetSpec(PresetSpecKey::for(new ImageSource('disk', 'uploads/photo.jpg', 'v2'), new PresetReference('card', '3f9a1c'))));

        $store->putPresetSpec($key, new ImageSpec(width: 400));
        $this->assertSame('w400.auto', $store->presetSpec($key)?->key());
    }

    public function test_clearing_a_source_deletes_its_preset_specs_and_counts_only_derivatives(): void
    {
        $store = $this->store();
        $source = new ImageSource('disk', 'uploads/photo.jpg', 'v1', mime: 'image/jpeg');
        $other = new ImageSource('disk', 'uploads/other.jpg', 'v1', mime: 'image/jpeg');
        $preset = new PresetReference('card', '3f9a1c');
        $store->put($this->derivativeKey(), $this->renderedImage());
        $store->putPresetSpec(PresetSpecKey::for($source, $preset), new ImageSpec(width: 200));
        $store->putPresetSpec(PresetSpecKey::for($other, $preset), new ImageSpec(width: 200));

        $this->assertSame(1, $store->clear($source));

        $this->assertNull($store->presetSpec(PresetSpecKey::for($source, $preset)));
        $this->assertNotNull($store->presetSpec(PresetSpecKey::for($other, $preset)));
    }

    public function test_a_url_is_null_or_a_string(): void
    {
        $store = $this->store();
        $key = $this->derivativeKey();
        $store->put($key, $this->renderedImage());

        $url = $store->url($key);

        $this->assertTrue($url === null || $url !== '');
    }
}
