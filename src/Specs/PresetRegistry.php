<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Contracts\Preset;
use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Urls\PresetReference;
use Illuminate\Contracts\Container\Container;

/**
 * The presets, by name: those of `image.presets`, read when first needed, and those
 * registered in code with Image::preset().
 */
final class PresetRegistry
{
    /** @var array<string, Preset>|null */
    private ?array $presets = null;

    /** @var array<string, Preset> */
    private array $registered = [];

    /**
     * @param  array<string, mixed>  $config  `image.presets`: definitions, or names of Preset classes
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $config = [],
    ) {}

    public function register(Preset $preset): void
    {
        $this->registered[$preset->name()] = $preset;
    }

    public function has(string $name): bool
    {
        return isset($this->all()[$name]);
    }

    public function find(string $name): ?Preset
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * @throws InvalidImageSpec when there is no such preset
     */
    public function get(string $name): Preset
    {
        return $this->find($name) ?? throw new InvalidImageSpec("Unknown preset: {$name}.");
    }

    /**
     * The preset at its current version, as URLs name it.
     */
    public static function reference(Preset $preset): PresetReference
    {
        return new PresetReference($preset->name(), $preset->version());
    }

    /**
     * @return array<string, Preset>
     */
    public function all(): array
    {
        $this->presets ??= $this->fromConfig();

        return [...$this->presets, ...$this->registered];
    }

    /**
     * @return array<string, Preset>
     */
    private function fromConfig(): array
    {
        $presets = [];

        foreach ($this->config as $name => $definition) {
            try {
                $preset = match (true) {
                    is_array($definition) => ArrayPreset::fromArray($name, $definition),
                    is_string($definition) && is_a($definition, Preset::class, true) => $this->container->make($definition),
                    default => throw new InvalidImageSpec('A preset is an array of options, or the name of a class that implements '.Preset::class.'.'),
                };
            } catch (InvalidImageSpec $exception) {
                throw new InvalidImageConfig("image.presets.{$name}: {$exception->getMessage()}", previous: $exception);
            }

            if (! $preset instanceof Preset || $preset->name() !== $name) {
                throw new InvalidImageConfig("image.presets.{$name}: the preset is named ".($preset instanceof Preset ? $preset->name() : '?').'.');
            }

            $presets[$name] = $preset;
        }

        return $presets;
    }
}
