<?php

declare(strict_types=1);

namespace Folklore\Image\Urls;

use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;

/**
 * What an image URL says, whatever its syntax: a source, and either a preset at one version
 * or a free spec, with the source version, an expiry and a signature. Codecs encode it into
 * a path and decode a path back into it.
 *
 * With a preset, a URL can only add a width, one of the preset's responsive widths (the
 * server checks it): anything else needs a free spec, which strict mode only renders when
 * it is signed (docs/decisions/0005-secure-by-default.md).
 */
final readonly class ImageUrl
{
    private function __construct(
        public string $resolver,
        public string $key,
        public Format $format,
        public ?PresetReference $preset,
        public ?int $width,
        public ?ImageSpec $spec,
        public ?string $version,
        public ?int $expires,
        public ?string $signature,
    ) {
        if (! ImageSource::isValidResolverName($resolver)) {
            throw new InvalidImageUrl("Invalid resolver name: {$resolver}.");
        }
        if (! ImageSource::isValidKey($key)) {
            throw new InvalidImageUrl("Invalid source key: {$key}.");
        }
        if ($format === Format::Auto) {
            throw new InvalidImageUrl('A URL has a concrete format: resolve the auto format from the source first.');
        }
        if ($width !== null && $width < 1) {
            throw new InvalidImageUrl("Invalid width: {$width}.");
        }
        if ($version !== null && ! ImageSource::isValidVersion($version)) {
            throw new InvalidImageUrl("Invalid source version: {$version}.");
        }
        if ($expires !== null && $expires < 1) {
            throw new InvalidImageUrl("Invalid expiry: {$expires}.");
        }
        if ($signature !== null && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $signature) !== 1) {
            throw new InvalidImageUrl('Invalid signature: signatures are 16 to 128 base64url characters.');
        }
    }

    /**
     * A URL for a free spec. Its format is the spec's, which can't be auto.
     */
    public static function forSpec(string $resolver, string $key, ImageSpec $spec, ?string $version = null, ?int $expires = null): self
    {
        return new self($resolver, $key, $spec->format, null, null, $spec, $version, $expires, null);
    }

    /**
     * A URL for a preset at one version, in a format, optionally at one of its responsive widths.
     */
    public static function forPreset(string $resolver, string $key, PresetReference $preset, Format $format, ?int $width = null, ?string $version = null, ?int $expires = null): self
    {
        return new self($resolver, $key, $format, $preset, $width, null, $version, $expires, null);
    }

    /**
     * A URL for a source, with its version. An auto format is resolved from the source.
     */
    public static function forSource(ImageSource $source, ImageSpec|PresetReference $spec, ?Format $format = null, ?int $width = null, ?int $expires = null): self
    {
        $format = ($format ?? ($spec instanceof ImageSpec ? $spec->format : Format::Auto))->resolve($source->mime);

        return $spec instanceof ImageSpec
            ? self::forSpec($source->resolver, $source->key, $spec->withFormat($format), $source->urlVersion(), $expires)
            : self::forPreset($source->resolver, $source->key, $spec, $format, $width, $source->urlVersion(), $expires);
    }

    public function withSignature(?string $signature): self
    {
        return new self($this->resolver, $this->key, $this->format, $this->preset, $this->width, $this->spec, $this->version, $this->expires, $signature);
    }

    public function isSigned(): bool
    {
        return $this->signature !== null;
    }

    /**
     * Whether the URL has expired at this time (a Unix timestamp).
     */
    public function hasExpired(int $now): bool
    {
        return $this->expires !== null && $this->expires <= $now;
    }

    /**
     * @return array{resolver: string, key: string, format: string, preset: array{name: string, version: string}|null, width: int|null, spec: array<string, mixed>|null, version: string|null, expires: int|null, signature: string|null}
     */
    public function toArray(): array
    {
        return [
            'resolver' => $this->resolver,
            'key' => $this->key,
            'format' => $this->format->value,
            'preset' => $this->preset === null ? null : ['name' => $this->preset->name, 'version' => $this->preset->version],
            'width' => $this->width,
            'spec' => $this->spec?->toArray(),
            'version' => $this->version,
            'expires' => $this->expires,
            'signature' => $this->signature,
        ];
    }
}
