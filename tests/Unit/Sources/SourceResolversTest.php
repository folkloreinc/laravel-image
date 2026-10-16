<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Sources;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Sources\SourceResolvers;
use Folklore\Image\Tests\Doubles\ArraySourceResolver;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Container\Container;

final class SourceResolversTest extends TestCase
{
    public function test_resolvers_of_the_config_are_built_by_their_factory_with_their_config(): void
    {
        $resolvers = $this->resolvers(['array' => ['key' => 'from-config']]);
        $given = null;
        $resolvers->extend('array', function (Container $app, array $config) use (&$given): SourceResolver {
            $given = $config;

            return new ArraySourceResolver(new ImageSource('array', 'from-config.jpg'));
        });

        $this->assertSame(['array'], array_keys($resolvers->all()));
        $this->assertSame(['key' => 'from-config'], $given);
        $this->assertSame('from-config.jpg', $resolvers->resolve('array://from-config.jpg')->key);
    }

    public function test_a_disabled_resolver_isnt_built(): void
    {
        $resolvers = $this->resolvers(['array' => null]);
        $resolvers->extend('array', fn () => $this->fail('The factory was called.'));

        $this->assertSame([], $resolvers->all());
    }

    public function test_registered_resolvers_are_used_whatever_the_config(): void
    {
        $resolvers = $this->resolvers([]);
        $resolvers->register(new ArraySourceResolver(new ImageSource('array', 'photo.jpg')));

        $this->assertSame('array', $resolvers->named('array')->name());
        $this->assertSame('array', $resolvers->for('array://photo.jpg')->name());
        $this->assertSame('photo.jpg', $resolvers->resolve('array://photo.jpg')->key);
    }

    public function test_a_source_is_its_own_reference(): void
    {
        $source = new ImageSource('media', 'med_01');

        $this->assertSame($source, $this->resolvers([])->resolve($source));
    }

    public function test_unknown_resolvers_and_references_are_refused(): void
    {
        $resolvers = $this->resolvers([]);

        $this->assertThrows(fn () => $resolvers->named('media'), SourceNotFound::class);
        $this->assertThrows(fn () => $resolvers->for('media://med_01'), UnsupportedReference::class);
        $this->assertThrows(fn () => $this->resolvers(['media' => []])->all(), InvalidImageConfig::class, 'image.resolvers.media');
        $this->assertThrows(fn () => $this->resolvers(['media' => 'yes'])->all(), InvalidImageConfig::class, 'image.resolvers.media');
    }

    public function test_a_factory_must_build_a_resolver_of_its_name(): void
    {
        $resolvers = $this->resolvers(['media' => []]);
        $resolvers->extend('media', fn () => new ArraySourceResolver);

        $this->assertThrows(fn () => $resolvers->all(), InvalidImageConfig::class, 'named array');
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function resolvers(array $config): SourceResolvers
    {
        $container = $this->app?->make(Container::class);
        $this->assertInstanceOf(Container::class, $container);

        return new SourceResolvers($container, $config);
    }
}
