<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Contracts\Preset;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Urls\PresetReference;

/**
 * A preset from the config:
 *
 *     'card' => ['width' => 800, 'ratio' => '16:9', 'widths' => [400, 800, 1200], 'formats' => ['avif', 'webp']],
 *
 * Its version is the first 6 hex characters of a SHA-256 of its canonical definition, so
 * the order of its options doesn't change it, and equivalent definitions share it.
 */
final readonly class ArrayPreset implements Preset
{
    /**
     * @param  list<positive-int>  $widths
     * @param  list<Format>  $formats
     */
    private function __construct(
        private string $name,
        private ImageSpec $spec,
        private array $widths,
        private array $formats,
    ) {}

    /**
     * @param  array<mixed>  $definition  the options of ImageSpec::fromArray(), plus `widths` and `formats`
     *
     * @throws InvalidImageSpec
     */
    public static function fromArray(string $name, array $definition): self
    {
        if (! PresetReference::isValidName($name)) {
            throw new InvalidImageSpec("Invalid preset name: {$name}. Use lowercase letters, digits, - and _.");
        }

        $widths = $definition['widths'] ?? [];
        $formats = $definition['formats'] ?? [];
        unset($definition['widths'], $definition['formats']);

        if (! is_array($widths) || ! is_array($formats)) {
            throw new InvalidImageSpec("The widths and formats of the {$name} preset are lists.");
        }

        $validWidths = [];
        foreach ($widths as $width) {
            if (! is_int($width) || $width < 1) {
                throw new InvalidImageSpec("The widths of the {$name} preset are positive integers.");
            }
            $validWidths[$width] = $width;
        }
        ksort($validWidths);

        $validFormats = [];
        foreach ($formats as $format) {
            $case = is_string($format) ? Format::tryFrom($format) : ($format instanceof Format ? $format : null);
            if ($case === null || $case === Format::Auto) {
                throw new InvalidImageSpec("The formats of the {$name} preset are jpeg, png, gif, webp or avif.");
            }
            $validFormats[$case->value] = $case;
        }

        return new self($name, ImageSpec::fromArray($definition), array_values($validWidths), array_values($validFormats));
    }

    public function name(): string
    {
        return $this->name;
    }

    public function version(): string
    {
        $definition = implode('|', [
            $this->spec->key(),
            implode(',', $this->widths),
            implode(',', array_map(fn (Format $format) => $format->value, $this->formats)),
        ]);

        return substr(hash('sha256', $definition), 0, 6);
    }

    public function spec(ImageSource $source): ImageSpec
    {
        return $this->spec;
    }

    public function widths(): array
    {
        return $this->widths;
    }

    public function formats(): array
    {
        return $this->formats;
    }

    public function reference(): PresetReference
    {
        return new PresetReference($this->name, $this->version());
    }
}
