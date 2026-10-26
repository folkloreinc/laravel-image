<?php

declare(strict_types=1);

namespace Folklore\Image\Specs;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Urls\SpecTokens;

/**
 * The canonical description of one rendering (docs/decisions/0004-specs-and-urls.md): the
 * source transform, the resize, the operations and the output.
 *
 * It is immutable, and canonical: options that have no effect are dropped and defaults are
 * removed, so two specs that render the same way have the same key(), whatever the order
 * their options were given in. For example, the fit and the focal point are dropped when
 * nothing is cropped or padded.
 *
 * Orientation, sRGB conversion and metadata stripping always apply, and the upscaling policy
 * comes from the config: none of them is part of a spec.
 */
final readonly class ImageSpec
{
    /** Names that operations can't use, since URLs use them for other options. */
    public const array RESERVED_NAMES = ['ar', 'exp', 'fit', 'flip', 'fp'];

    public ?Transform $transform;

    public ?int $width;

    public ?int $height;

    /** How the image fits a box of two dimensions. Cover when there is no such box. */
    public Fit $fit;

    /** With a width or a height, gives the other one; alone, crops (or pads) the source to it. */
    public ?Ratio $ratio;

    /**
     * The point to keep when cropping, relative to the upright original like the source's
     * focal point (the renderer maps it through the transform), or null for the source's focal
     * point, or the center.
     */
    public ?Focus $focus;

    /**
     * Operations by name, sorted by name, with their arguments. The renderer applies them in
     * the order they are registered in, not in this order.
     *
     * @var array<string, list<string>>
     */
    public array $operations;

    public Format $format;

    /** From 1 to 100, or null for the config's quality for the format. */
    public ?int $quality;

    /**
     * @param  array<string, list<int|string>>|list<string>  $operations  `['grayscale' => [], 'blur' => [5]]`, or names without arguments
     */
    public function __construct(
        ?Transform $transform = null,
        ?int $width = null,
        ?int $height = null,
        ?Fit $fit = null,
        ?Ratio $ratio = null,
        ?Focus $focus = null,
        array $operations = [],
        Format $format = Format::Auto,
        ?int $quality = null,
    ) {
        foreach (['width' => $width, 'height' => $height] as $name => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidImageSpec("The {$name} must be at least 1, not {$value}.");
            }
        }
        if ($width !== null && $height !== null && $ratio !== null) {
            throw new InvalidImageSpec('Give two of the width, the height and the ratio, not the three.');
        }
        if ($quality !== null && ($quality < 1 || $quality > 100)) {
            throw new InvalidImageSpec("The quality is between 1 and 100, not {$quality}.");
        }

        // The focal point of a transform (Panneau's shape) is the spec's focus.
        $focus ??= $transform?->focalPoint;
        $transform = $transform?->withoutFocalPoint();
        $this->transform = $transform === null || $transform->isEmpty() ? null : $transform;
        $this->width = $width;
        $this->height = $height;
        $this->ratio = $ratio;
        $this->fit = $this->hasBox() ? ($fit ?? Fit::Cover) : Fit::Cover;
        $this->focus = $this->hasBox() && $this->fit === Fit::Cover ? $focus : null;
        $this->operations = self::normalizeOperations($operations);
        $this->format = $format;
        $this->quality = $quality;
    }

    /**
     * Builds a spec from the array form of presets and of toArray():
     *
     *     ['width' => 800, 'ratio' => '16:9', 'fit' => 'cover', 'focus' => [0.5, 0.3],
     *      'crop' => [...], 'rotate' => 90, 'flip' => 'horizontal',
     *      'operations' => ['grayscale', 'blur' => 5], 'format' => 'webp', 'quality' => 80]
     *
     * @param  array<mixed>  $options
     *
     * @throws InvalidImageSpec for unknown keys and invalid values
     */
    public static function fromArray(array $options): self
    {
        $known = ['crop', 'rotate', 'flip', 'width', 'height', 'fit', 'ratio', 'focus', 'operations', 'format', 'quality'];
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new InvalidImageSpec('Unknown spec options: '.implode(', ', $unknown).'.');
        }

        $operations = $options['operations'] ?? [];
        if (! is_array($operations)) {
            throw new InvalidImageSpec('The operations are an array.');
        }

        return new self(
            transform: Transform::fromArray(array_intersect_key($options, array_flip(['crop', 'rotate', 'flip']))),
            width: self::optionalInt($options, 'width'),
            height: self::optionalInt($options, 'height'),
            fit: self::optionalEnum($options, 'fit', Fit::class),
            ratio: self::optionalRatio($options),
            focus: self::optionalFocus($options),
            operations: self::operationsFromArray($operations),
            format: self::optionalEnum($options, 'format', Format::class) ?? Format::Auto,
            quality: self::optionalInt($options, 'quality'),
        );
    }

    /**
     * The options that differ from the defaults, in the form fromArray() reads.
     *
     * @return array{crop?: array{x: int, y: int, width: int, height: int}, rotate?: int, flip?: string, width?: int, height?: int, ratio?: string, fit?: string, focus?: array{x: float, y: float}, operations?: array<string, list<string>>, format?: string, quality?: int}
     */
    public function toArray(): array
    {
        return array_filter([
            'crop' => $this->transform?->crop?->toArray(),
            'rotate' => $this->transform === null || $this->transform->rotate === 0 ? null : $this->transform->rotate,
            'flip' => $this->transform?->flip?->value,
            'width' => $this->width,
            'height' => $this->height,
            'ratio' => $this->ratio?->toString(),
            'fit' => $this->fit === Fit::Cover ? null : $this->fit->value,
            'focus' => $this->focus?->toArray(),
            'operations' => $this->operations === [] ? null : $this->operations,
            'format' => $this->format === Format::Auto ? null : $this->format->value,
            'quality' => $this->quality,
        ], fn ($value) => $value !== null);
    }

    /**
     * A copy with some options changed, in the form of fromArray(). A null value removes an option.
     *
     * @param  array<mixed>  $options
     */
    public function with(array $options): self
    {
        return self::fromArray(array_filter([...$this->toArray(), ...$options], fn ($value) => $value !== null));
    }

    public function withFormat(Format $format): self
    {
        return new self($this->transform, $this->width, $this->height, $this->fit, $this->ratio, $this->focus, $this->operations, $format, $this->quality);
    }

    /**
     * The spec as the source is rendered with it: with the source's transform when the spec
     * has none of its own.
     */
    public function forSource(ImageSource $source): self
    {
        return $this->transform !== null || $source->transform === null
            ? $this
            : new self($source->transform, $this->width, $this->height, $this->fit, $this->ratio, $this->focus, $this->operations, $this->format, $this->quality);
    }

    /**
     * The same rendering at another width, as a preset at one of its responsive widths: a
     * height scales with the width, and a ratio is kept, so the shape doesn't change.
     *
     * @throws InvalidImageSpec when the width is less than 1
     */
    public function atWidth(int $width): self
    {
        $height = $this->width !== null && $this->height !== null
            ? max(1, (int) round($this->height * $width / $this->width))
            : null;

        return new self($this->transform, $width, $height, $this->fit, $this->ratio, $this->focus, $this->operations, $this->format, $this->quality);
    }

    /**
     * The canonical key: equal for specs that render the same way. It names derivatives on
     * disk, and uses the syntax of v2 URLs: `w800,h450,fit-contain.webp`, `_.auto`.
     */
    public function key(): string
    {
        $tokens = SpecTokens::encode($this);

        return ($tokens === [] ? '_' : implode(',', $tokens)).'.'.$this->format->value;
    }

    public function equals(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /**
     * Whether the spec defines a box of two dimensions, which the image is fitted to.
     */
    public function hasBox(): bool
    {
        return ($this->width !== null && $this->height !== null) || $this->ratio !== null;
    }

    /**
     * The size of the output for a source of this size (upright, as displayed). It needs no
     * file: variants get their dimensions from the source's metadata.
     *
     * @param  bool  $upscale  whether the output can be larger than the source (from the config,
     *                         never from a URL). Without upscaling, a box keeps its ratio at the
     *                         largest size the source allows.
     *
     * @throws InvalidImageSpec when the crop is outside the source
     */
    public function outputSize(Size $source, bool $upscale = false): Size
    {
        $source = $this->transform?->apply($source) ?? $source;
        $box = $this->box($source);

        if ($box === null) {
            return $this->resizeProportionally($source, $upscale);
        }

        if ($this->fit === Fit::Inside) {
            $factor = min($box->width / $source->width, $box->height / $source->height);

            return $source->scaled($upscale ? $factor : min($factor, 1));
        }

        if ($upscale) {
            return $box;
        }

        // Cover and fill scale the source until it fills the box; contain until it fits inside.
        $factor = $this->fit === Fit::Contain
            ? max($source->width / $box->width, $source->height / $box->height)
            : min($source->width / $box->width, $source->height / $box->height);

        return $factor >= 1 ? $box : $box->scaled($factor);
    }

    /**
     * The box of two dimensions the image is fitted to, for a source of this size.
     */
    private function box(Size $source): ?Size
    {
        if ($this->width !== null && $this->height !== null) {
            return new Size($this->width, $this->height);
        }

        if ($this->ratio === null) {
            return null;
        }

        $ratio = $this->ratio->value();

        if ($this->width !== null) {
            return Size::round($this->width, $this->width / $ratio);
        }
        if ($this->height !== null) {
            return Size::round($this->height * $ratio, $this->height);
        }

        // The ratio alone: the largest box of that ratio inside the source, or, for contain,
        // the smallest one around it.
        $wider = $source->ratio() > $ratio;
        $around = $this->fit === Fit::Contain;

        return $wider !== $around
            ? Size::round($source->height * $ratio, $source->height)
            : Size::round($source->width, $source->width / $ratio);
    }

    private function resizeProportionally(Size $source, bool $upscale): Size
    {
        $factor = match (true) {
            $this->width !== null => $this->width / $source->width,
            $this->height !== null => $this->height / $source->height,
            default => 1,
        };

        return $source->scaled($upscale ? $factor : min($factor, 1));
    }

    /**
     * @param  array<int|string, array<int|string>|string>  $operations
     * @return array<string, list<string>>
     */
    private static function normalizeOperations(array $operations): array
    {
        $normalized = [];

        foreach ($operations as $name => $arguments) {
            if (is_int($name)) {
                [$name, $arguments] = [$arguments, []];
            }
            if (! is_string($name) || ! is_array($arguments)) {
                throw new InvalidImageSpec('Operations are names, or names with a list of arguments.');
            }
            if (preg_match('/^[a-z]{2,32}$/', $name) !== 1 || in_array($name, self::RESERVED_NAMES, true)) {
                throw new InvalidImageSpec("Invalid operation name: {$name}. Use 2 to 32 lowercase letters, other than ".implode(', ', self::RESERVED_NAMES).'.');
            }

            $normalized[$name] = array_map(function (int|string $argument) use ($name): string {
                $argument = (string) $argument;
                if (preg_match('/^[a-z0-9]{1,32}$/', $argument) !== 1) {
                    throw new InvalidImageSpec("Invalid argument for the {$name} operation: {$argument}. Arguments are lowercase letters and digits.");
                }

                return $argument;
            }, array_values($arguments));
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    /**
     * @param  array<mixed>  $operations
     * @return array<string, list<int|string>>|list<string>
     */
    private static function operationsFromArray(array $operations): array
    {
        $normalized = [];

        foreach ($operations as $name => $arguments) {
            if (is_int($name) && is_string($arguments)) {
                $normalized[$arguments] = [];
            } elseif (is_string($name) && (is_int($arguments) || is_string($arguments))) {
                $normalized[$name] = [$arguments];
            } elseif (is_string($name) && is_array($arguments) && array_is_list($arguments)) {
                $normalized[$name] = array_map(fn ($argument) => is_int($argument) || is_string($argument)
                    ? $argument
                    : throw new InvalidImageSpec("The arguments of the {$name} operation are integers or strings."), $arguments);
            } else {
                throw new InvalidImageSpec('Operations are names, or names with their arguments.');
            }
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $options
     */
    private static function optionalInt(array $options, string $key): ?int
    {
        $value = $options[$key] ?? null;

        return $value === null || is_int($value) ? $value : throw new InvalidImageSpec("The {$key} is an integer.");
    }

    /**
     * @template T of Fit|Format
     *
     * @param  array<mixed>  $options
     * @param  class-string<T>  $enum
     * @return T|null
     */
    private static function optionalEnum(array $options, string $key, string $enum): Fit|Format|null
    {
        $value = $options[$key] ?? null;

        if ($value === null || $value instanceof $enum) {
            return $value;
        }

        return is_string($value) && ($case = $enum::tryFrom($value)) !== null
            ? $case
            : throw new InvalidImageSpec("Invalid {$key}: ".(is_scalar($value) ? $value : get_debug_type($value)).'.');
    }

    /**
     * @param  array<mixed>  $options
     */
    private static function optionalRatio(array $options): ?Ratio
    {
        $ratio = $options['ratio'] ?? null;

        return match (true) {
            $ratio === null, $ratio instanceof Ratio => $ratio,
            is_string($ratio) => Ratio::fromString($ratio),
            default => throw new InvalidImageSpec('A ratio is a string such as 16:9.'),
        };
    }

    /**
     * @param  array<mixed>  $options
     */
    private static function optionalFocus(array $options): ?Focus
    {
        $focus = $options['focus'] ?? null;

        return match (true) {
            $focus === null, $focus instanceof Focus => $focus,
            is_array($focus) => Focus::fromArray($focus),
            default => throw new InvalidImageSpec('A focal point is an array of x and y.'),
        };
    }
}
