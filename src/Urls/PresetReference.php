<?php

declare(strict_types=1);

namespace Folklore\Image\Urls;

use Folklore\Image\Exceptions\InvalidImageUrl;

/**
 * A preset at one version, as URLs name it: `card.3f9a1c`.
 */
final readonly class PresetReference
{
    public function __construct(
        public string $name,
        public string $version,
    ) {
        if (! self::isValidName($name)) {
            throw new InvalidImageUrl("Invalid preset name: {$name}. Use lowercase letters, digits, - and _.");
        }
        if (preg_match('/^[a-z0-9]{1,16}$/', $version) !== 1) {
            throw new InvalidImageUrl("Invalid preset version: {$version}.");
        }
    }

    public static function isValidName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $name) === 1;
    }

    public function toString(): string
    {
        return "{$this->name}.{$this->version}";
    }
}
