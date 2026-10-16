<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Doubles;

use Folklore\Image\Contracts\SourceResolver;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Sources\ImageSource;

/**
 * Resolves `array://{key}` references and keys to the sources it was given.
 */
final readonly class ArraySourceResolver implements SourceResolver
{
    /** @var array<string, ImageSource> */
    private array $sources;

    public function __construct(ImageSource ...$sources)
    {
        $byKey = [];
        foreach ($sources as $source) {
            $byKey[$source->key] = $source;
        }

        $this->sources = $byKey;
    }

    public function name(): string
    {
        return 'array';
    }

    public function supports(mixed $reference): bool
    {
        return is_string($reference) && str_starts_with($reference, 'array://');
    }

    public function resolve(mixed $reference): ImageSource
    {
        if (! is_string($reference) || ! $this->supports($reference)) {
            throw new UnsupportedReference('Not an array:// reference.');
        }

        return $this->resolveKey(substr($reference, strlen('array://')));
    }

    public function resolveKey(string $key): ImageSource
    {
        return $this->sources[$key] ?? throw new SourceNotFound("No source for the key {$key}.");
    }
}
