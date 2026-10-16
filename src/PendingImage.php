<?php

declare(strict_types=1);

namespace Folklore\Image;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Folklore\Image\Contracts\Preset;
use Folklore\Image\Exceptions\InvalidImageSource;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Specs\Ratio;
use Folklore\Image\Specs\Size;
use Folklore\Image\Urls\ImageUrl;
use Folklore\Image\Variants\ImageVariant;
use Illuminate\Support\Collection;
use Stringable;

/**
 * An image being described, from Image::for($reference). It is immutable: every method
 * returns a new instance, so a base can be shared.
 *
 *     Image::for($media)->preset('card')->url();
 *     Image::for('uploads/photo.jpg')->width(800)->ratio('16:9')->avif()->signed()->url();
 *     Image::for($media)->variants('card');
 *
 * A preset gives a preset URL, which strict mode serves unsigned, as long as only its width
 * (one of its responsive widths) and its format change. Any other option makes a free spec,
 * which strict mode only serves signed (docs/decisions/0009-v2-url-syntax.md).
 */
final class PendingImage implements Stringable
{
    private ?Preset $preset = null;

    private ?int $width = null;

    /** @var array<string, mixed> other options of the spec, in the form of ImageSpec::fromArray() */
    private array $options = [];

    private ?Format $format = null;

    private bool $signed = false;

    /** @var int|false|null a Unix timestamp, false for no expiry, null for the default TTL */
    private int|false|null $expires = null;

    private ?string $host = null;

    public function __construct(
        private readonly ImageManager $manager,
        public readonly ImageSource $source,
    ) {}

    /**
     * Starts from a preset: by name, or a Preset instance.
     *
     * @throws InvalidImageSpec when there is no preset of this name
     */
    public function preset(string|Preset $preset): self
    {
        $preset = is_string($preset) ? $this->manager->presets()->get($preset) : $preset;

        return $this->copy(fn (self $image) => $image->preset = $preset);
    }

    /**
     * The width. On a preset, the height scales with it and the ratio is kept; one of the
     * preset's widths keeps a preset URL.
     */
    public function width(int $width): self
    {
        return $this->copy(fn (self $image) => $image->width = $width);
    }

    public function height(int $height): self
    {
        return $this->withOptions(['height' => $height]);
    }

    /**
     * The width and the height, which replace a ratio.
     */
    public function size(int $width, int $height): self
    {
        return $this->withOptions(['height' => $height, 'ratio' => null])->width($width);
    }

    public function fit(Fit|string $fit): self
    {
        return $this->withOptions(['fit' => $fit instanceof Fit ? $fit->value : $fit]);
    }

    /**
     * @param  Ratio|string|int  $ratio  `'16:9'`, a Ratio, or the width of the ratio with its height
     */
    public function ratio(Ratio|string|int $ratio, ?int $height = null): self
    {
        $ratio = match (true) {
            $ratio instanceof Ratio => $ratio,
            is_int($ratio) => new Ratio($ratio, $height ?? throw new InvalidImageSpec('A ratio needs a width and a height.')),
            default => Ratio::fromString($ratio),
        };

        return $this->withOptions(['ratio' => $ratio->toString()]);
    }

    public function format(Format|string $format): self
    {
        $format = $format instanceof Format ? $format : (Format::tryFrom($format) ?? throw new InvalidImageSpec("Unknown format: {$format}."));

        return $this->copy(fn (self $image) => $image->format = $format);
    }

    public function jpeg(): self
    {
        return $this->format(Format::Jpeg);
    }

    public function png(): self
    {
        return $this->format(Format::Png);
    }

    public function gif(): self
    {
        return $this->format(Format::Gif);
    }

    public function webp(): self
    {
        return $this->format(Format::Webp);
    }

    public function avif(): self
    {
        return $this->format(Format::Avif);
    }

    /**
     * From 1 to 100. Without it, the quality is the config's for the format.
     */
    public function quality(int $quality): self
    {
        return $this->withOptions(['quality' => $quality]);
    }

    /**
     * Signs the URL, so strict mode serves it whatever its spec.
     *
     * @param  DateTimeInterface|DateInterval|int|false|null  $expires  when the URL stops working: a time, an interval
     *                                                                  or a number of seconds from now; null for
     *                                                                  `image.signing.default_ttl`, false for never
     */
    public function signed(DateTimeInterface|DateInterval|int|false|null $expires = null): self
    {
        $timestamp = match (true) {
            $expires instanceof DateTimeInterface => $expires->getTimestamp(),
            $expires instanceof DateInterval => (new DateTimeImmutable('@'.$this->manager->now()))->add($expires)->getTimestamp(),
            is_int($expires) => $this->manager->now() + $expires,
            default => $expires,
        };

        return $this->copy(function (self $image) use ($timestamp): void {
            $image->signed = true;
            $image->expires = $timestamp;
        });
    }

    /**
     * The host of this URL, instead of `image.url.host`: for a site with several domains.
     */
    public function host(?string $host): self
    {
        $host = $host === null ? null : rtrim($host, '/');

        return $this->copy(fn (self $image) => $image->host = $host);
    }

    /**
     * The spec this image renders with, its format resolved from the source.
     */
    public function spec(): ImageSpec
    {
        $spec = $this->preset?->spec($this->source) ?? new ImageSpec;
        $options = $this->options;

        if ($this->width !== null && ($this->preset === null || array_key_exists('height', $options))) {
            $options['width'] = $this->width;
        }
        if ($options !== []) {
            // Two of the width, the height and the ratio make the third: the one asked for here
            // replaces one of the preset's. A height keeps the ratio, a ratio keeps the width.
            $merged = [...$spec->toArray(), ...$options];
            if (isset($merged['width'], $merged['height'], $merged['ratio'])) {
                $given = fn (string $option) => array_key_exists($option, $options);
                $replaced = match (true) {
                    $given('width') && ! $given('ratio') => 'ratio',
                    $given('ratio') && ! $given('height') => 'height',
                    ! $given('width') => 'width',
                    default => null,
                };
                if ($replaced !== null) {
                    $options[$replaced] = null;
                }
            }
            $spec = $spec->with($options);
        }
        if ($this->width !== null && ! array_key_exists('width', $options)) {
            $spec = $spec->atWidth($this->width);
        }

        return $spec->withFormat(($this->format ?? $spec->format)->resolve($this->source->mime));
    }

    /**
     * The URL of the image, signed when asked for, or when the source is private.
     *
     * @throws InvalidImageUrl when strict mode wouldn't serve it: a free spec that isn't signed
     * @throws InvalidImageSpec when it is over the limits
     */
    public function url(): string
    {
        return $this->manager->url($this->imageUrl(), $this->host);
    }

    /**
     * What the URL says, before it is encoded.
     */
    public function imageUrl(): ImageUrl
    {
        $config = $this->manager->config();
        $spec = $this->spec();
        $config->limits()->check($spec, $this->outputSize($spec));

        $signed = $this->signed || $this->source->isPrivate();
        $expires = $signed ? $this->expiry($config) : null;

        $presetWidth = $this->presetWidth();
        $url = $presetWidth !== false && $this->preset !== null
            ? ImageUrl::forSource($this->source, PresetRegistry::reference($this->preset), $spec->format, $presetWidth, $expires)
            : ImageUrl::forSource($this->source, $spec, expires: $expires);

        if (! $signed && $url->preset === null && $config->strict()) {
            throw new InvalidImageUrl('Strict mode only serves presets, and signed URLs: call signed(), or use a preset and only change its width (one of its widths) and its format.');
        }

        return $signed ? $url->withSignature($this->manager->signer()->sign($this->manager->codec()->payload($url))) : $url;
    }

    /**
     * This image as a variant: its URL, its exact dimensions and its type, from the source's
     * metadata, without reading the file.
     *
     * @throws InvalidImageSource when the source's dimensions aren't known
     */
    public function variant(): ImageVariant
    {
        $spec = $this->spec();
        $size = $this->outputSize($spec) ?? throw new InvalidImageSource("The dimensions of the source {$this->source->resolver}/{$this->source->key} aren't known: its resolver gives none.");

        return new ImageVariant($this->url(), $size->width, $size->height, $spec->format, $this->preset?->name());
    }

    /**
     * The responsive variants of a preset: each of its widths in each of its formats, then in
     * the fallback format (the preset's, or the source's), by format, then by width. Without
     * widths, the preset's own width. A format given with format() is the only one.
     *
     * @return Collection<int, ImageVariant>
     */
    public function variants(string|Preset|null $preset = null): Collection
    {
        $image = $preset === null ? $this : $this->preset($preset);
        if ($image->preset === null) {
            return new Collection([$image->variant()]);
        }

        $widths = $image->width === null ? $image->preset->widths() : [];
        $fallback = $image->spec()->format;
        $formats = $image->format === null ? [...$image->preset->formats(), $fallback] : [$image->format];

        $variants = [];
        foreach (array_unique($formats, SORT_REGULAR) as $format) {
            foreach ($widths === [] ? [null] : $widths as $width) {
                $variant = $image->format($format);
                $variants[] = ($width === null ? $variant : $variant->width($width))->variant();
            }
        }

        return new Collection($variants);
    }

    /**
     * Renders the image now, in code, with the configured renderer. Nothing is stored.
     */
    public function render(): RenderedImage
    {
        return $this->manager->renderer()->render($this->source, $this->spec());
    }

    public function __toString(): string
    {
        return $this->url();
    }

    /**
     * The width a preset URL adds (null for the preset's own), or false when the image needs a
     * free spec: an option other than the width and the format, or a width that isn't one of
     * the preset's.
     */
    private function presetWidth(): int|false|null
    {
        if ($this->preset === null || $this->options !== []) {
            return false;
        }
        if ($this->width === null || $this->width === $this->preset->spec($this->source)->width) {
            return null;
        }

        return in_array($this->width, $this->preset->widths(), true) ? $this->width : false;
    }

    private function outputSize(ImageSpec $spec): ?Size
    {
        $source = $this->source->size();

        return $source === null ? null : $spec->outputSize($source, $this->manager->config()->upscale());
    }

    private function expiry(ImageConfig $config): ?int
    {
        $expires = $this->expires ?? (($ttl = $config->defaultTtl()) === null ? false : $this->manager->now() + $ttl);

        if ($expires === false && $this->source->isPrivate()) {
            throw new InvalidImageUrl($this->expires === false
                ? 'The URLs of a private source expire: give signed() an expiry.'
                : 'The URLs of a private source expire: give signed() an expiry, or set image.signing.default_ttl.');
        }

        return $expires === false ? null : $expires;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function withOptions(array $options): self
    {
        return $this->copy(fn (self $image) => $image->options = [...$image->options, ...$options]);
    }

    /**
     * @param  callable(self): mixed  $change
     */
    private function copy(callable $change): self
    {
        $copy = clone $this;
        $change($copy);

        return $copy;
    }
}
