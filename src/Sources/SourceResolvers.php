<?php

declare(strict_types=1);

namespace Folklore\Image\Sources;

use Closure;
use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Illuminate\Contracts\Container\Container;

/**
 * The source resolvers (docs/decisions/0006-sources-and-resolvers.md):
 *
 * - those of `image.resolvers`, in the config's order, each built by the factory registered
 *   for its name with extend() and given its config: `'resolvers' => ['disk' => ['disk' => 'public']]`;
 * - those registered with Image::resolver(), such as the `media` resolver of laravel-mediatheque 2.
 *
 * A reference goes to the first resolver that supports it; a URL names its resolver.
 */
final class SourceResolvers
{
    /** @var array<string, Closure(Container, array<mixed>): SourceResolver> */
    private array $factories = [];

    /** @var array<string, SourceResolver>|null */
    private ?array $configured = null;

    /** @var array<string, SourceResolver> */
    private array $registered = [];

    /**
     * @param  array<string, mixed>  $config  `image.resolvers`: the config of each resolver, by name
     */
    public function __construct(
        private readonly Container $container,
        private readonly array $config = [],
    ) {}

    /**
     * Registers the factory of a resolver of the config, by its name.
     *
     * @param  Closure(Container, array<mixed>): SourceResolver  $factory
     */
    public function extend(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
        $this->configured = null;
    }

    /**
     * Registers a resolver, whatever the config says. It replaces a resolver of the same name.
     */
    public function register(SourceResolver $resolver): void
    {
        $this->registered[$resolver->name()] = $resolver;
    }

    /**
     * The resolver of a URL.
     *
     * @throws SourceNotFound when there is no resolver of this name
     */
    public function named(string $name): SourceResolver
    {
        return $this->all()[$name] ?? throw new SourceNotFound("No image resolver is named {$name}.");
    }

    /**
     * The first resolver that supports a reference.
     *
     * @throws UnsupportedReference
     */
    public function for(mixed $reference): SourceResolver
    {
        foreach ($this->all() as $resolver) {
            if ($resolver->supports($reference)) {
                return $resolver;
            }
        }

        throw new UnsupportedReference('No image resolver supports '.(is_string($reference) ? "the reference {$reference}" : 'a reference of type '.get_debug_type($reference)).'.');
    }

    /**
     * The source of a reference. A source is its own reference.
     *
     * @throws UnsupportedReference
     * @throws SourceNotFound
     */
    public function resolve(mixed $reference): ImageSource
    {
        return $reference instanceof ImageSource ? $reference : $this->for($reference)->resolve($reference);
    }

    /**
     * @return array<string, SourceResolver>
     */
    public function all(): array
    {
        $this->configured ??= $this->fromConfig();

        return [...$this->configured, ...$this->registered];
    }

    /**
     * @return array<string, SourceResolver>
     */
    private function fromConfig(): array
    {
        $resolvers = [];

        foreach ($this->config as $name => $config) {
            if ($config === null || $config === false) {
                continue;
            }
            if (! is_array($config)) {
                throw new InvalidImageConfig("image.resolvers.{$name} is the array of options of the resolver.");
            }
            if (! isset($this->factories[$name])) {
                throw new InvalidImageConfig("image.resolvers.{$name}: there is no resolver of this name.");
            }

            $resolver = ($this->factories[$name])($this->container, $config);
            if ($resolver->name() !== $name) {
                throw new InvalidImageConfig("image.resolvers.{$name}: the resolver is named {$resolver->name()}.");
            }

            $resolvers[$name] = $resolver;
        }

        return $resolvers;
    }
}
