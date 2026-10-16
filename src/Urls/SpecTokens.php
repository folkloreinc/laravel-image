<?php

declare(strict_types=1);

namespace Folklore\Image\Urls;

use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Specs\Crop;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Flip;
use Folklore\Image\Specs\Focus;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Ratio;
use Folklore\Image\Specs\Transform;

/**
 * The options of a spec as the tokens of v2 URLs and derivative keys
 * (docs/decisions/0009-v2-url-syntax.md), in their canonical order:
 *
 *     c-{x}-{y}-{width}-{height}   crop, in source pixels
 *     r-{90|180|270}               rotation, clockwise
 *     flip-{h|v|hv}                flip
 *     w{width}  h{height}          size
 *     ar-{width}-{height}          ratio
 *     fit-{contain|inside|fill}    fit, when not cover
 *     fp-{x}-{y}                   focal point, in percent
 *     {name}[-{argument}…]         operations, sorted by name
 *     q{quality}                   quality
 *
 * The format is the URL's extension, so it isn't a token.
 *
 * @internal
 */
final class SpecTokens
{
    private const array FLIPS = ['h' => Flip::Horizontal, 'v' => Flip::Vertical, 'hv' => Flip::Both];

    /**
     * @return list<string>
     */
    public static function encode(ImageSpec $spec): array
    {
        $tokens = [];
        $transform = $spec->transform;

        if ($transform?->crop !== null) {
            $crop = $transform->crop;
            $tokens[] = "c-{$crop->x}-{$crop->y}-{$crop->width}-{$crop->height}";
        }
        if ($transform !== null && $transform->rotate !== 0) {
            $tokens[] = "r-{$transform->rotate}";
        }
        if ($transform?->flip !== null) {
            $tokens[] = 'flip-'.array_search($transform->flip, self::FLIPS, true);
        }
        if ($spec->width !== null) {
            $tokens[] = "w{$spec->width}";
        }
        if ($spec->height !== null) {
            $tokens[] = "h{$spec->height}";
        }
        if ($spec->ratio !== null) {
            $tokens[] = "ar-{$spec->ratio->width}-{$spec->ratio->height}";
        }
        if ($spec->fit !== Fit::Cover) {
            $tokens[] = "fit-{$spec->fit->value}";
        }
        if ($spec->focus !== null) {
            $tokens[] = 'fp-'.((int) round($spec->focus->x * 100)).'-'.((int) round($spec->focus->y * 100));
        }
        foreach ($spec->operations as $name => $arguments) {
            $tokens[] = implode('-', [$name, ...$arguments]);
        }
        if ($spec->quality !== null) {
            $tokens[] = "q{$spec->quality}";
        }

        return $tokens;
    }

    /**
     * Reads tokens back into a spec. It doesn't check their order: decoders compare the
     * result's tokens with the ones they read, so a URL has one spelling only.
     *
     * @param  list<string>  $tokens
     *
     * @throws InvalidImageUrl
     */
    public static function decode(array $tokens, Format $format): ImageSpec
    {
        $crop = $fit = $ratio = $focus = $flip = $width = $height = $quality = null;
        $rotate = 0;
        $operations = [];

        foreach ($tokens as $token) {
            $int = fn (string $digits): int => (int) $digits;

            match (true) {
                preg_match('/^c-(\d{1,9})-(\d{1,9})-(\d{1,9})-(\d{1,9})$/', $token, $m) === 1 => $crop = [$int($m[1]), $int($m[2]), $int($m[3]), $int($m[4])],
                preg_match('/^r-(\d{1,3})$/', $token, $m) === 1 => $rotate = $int($m[1]),
                preg_match('/^flip-(h|v|hv)$/', $token, $m) === 1 => $flip = self::FLIPS[$m[1]],
                preg_match('/^w(\d{1,9})$/', $token, $m) === 1 => $width = $int($m[1]),
                preg_match('/^h(\d{1,9})$/', $token, $m) === 1 => $height = $int($m[1]),
                preg_match('/^ar-(\d{1,9})-(\d{1,9})$/', $token, $m) === 1 => $ratio = [$int($m[1]), $int($m[2])],
                preg_match('/^fit-([a-z]+)$/', $token, $m) === 1 => $fit = Fit::tryFrom($m[1]) ?? throw new InvalidImageUrl("Unknown fit: {$m[1]}."),
                preg_match('/^fp-(\d{1,3})-(\d{1,3})$/', $token, $m) === 1 => $focus = [$int($m[1]) / 100, $int($m[2]) / 100],
                preg_match('/^q(\d{1,3})$/', $token, $m) === 1 => $quality = $int($m[1]),
                preg_match('/^([a-z]{2,32})((?:-[a-z0-9]{1,32})*)$/', $token, $m) === 1 && ! in_array($m[1], ImageSpec::RESERVED_NAMES, true) => $operations[$m[1]] = $m[2] === '' ? [] : explode('-', substr($m[2], 1)),
                default => throw new InvalidImageUrl("Unknown spec token: {$token}."),
            };
        }

        try {
            return new ImageSpec(
                transform: new Transform($crop === null ? null : new Crop(...$crop), $rotate, $flip),
                width: $width,
                height: $height,
                fit: $fit,
                ratio: $ratio === null ? null : new Ratio(...$ratio),
                focus: $focus === null ? null : new Focus(...$focus),
                operations: $operations,
                format: $format,
                quality: $quality,
            );
        } catch (InvalidImageSpec $exception) {
            throw new InvalidImageUrl($exception->getMessage(), previous: $exception);
        }
    }
}
