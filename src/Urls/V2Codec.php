<?php

declare(strict_types=1);

namespace Folklore\Image\Urls;

use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;

/**
 * The v2 URL syntax (docs/decisions/0009-v2-url-syntax.md), after the route prefix:
 *
 *     {signature}/{spec}/{resolver}/{key}.{extension}
 *
 *     _/card.3f9a1c,v-8c1f/media/med_01JAB3QX.webp
 *     Hk2x…/w800,h450,fp-30-60,v-8c1f,exp-1767225600/disk/uploads/2026/photo.jpg.avif
 *
 * Every URL has one spelling: decode() rejects any other, such as options in another order,
 * a default written out, `.jpeg` for `.jpg` or a segment encoded differently.
 */
final class V2Codec implements UrlCodec
{
    public function encode(ImageUrl $url): string
    {
        if ($url->expires !== null && $url->signature === null) {
            throw new InvalidImageUrl('A URL that expires is signed.');
        }

        return ($url->signature ?? '_').'/'.$this->payload($url);
    }

    public function payload(ImageUrl $url): string
    {
        $tokens = $url->preset !== null
            ? [$url->preset->toString(), ...($url->width === null ? [] : ["w{$url->width}"])]
            : SpecTokens::encode($url->spec ?? new ImageSpec);

        if ($url->version !== null) {
            $tokens[] = "v-{$url->version}";
        }
        if ($url->expires !== null) {
            $tokens[] = "exp-{$url->expires}";
        }

        $key = implode('/', array_map(rawurlencode(...), explode('/', $url->key)));

        return ($tokens === [] ? '_' : implode(',', $tokens))."/{$url->resolver}/{$key}.{$url->format->extension()}";
    }

    public function decode(string $path): ImageUrl
    {
        $segments = explode('/', $path);
        if (count($segments) < 4) {
            throw new InvalidImageUrl("Not a v2 image URL: {$path}.");
        }

        [$signature, $spec, $resolver] = $segments;
        $rawKey = implode('/', array_slice($segments, 3));

        $dot = strrpos($rawKey, '.');
        $format = $dot === false ? null : Format::tryFromExtension(substr($rawKey, $dot + 1));
        if ($dot === false || $format === null) {
            throw new InvalidImageUrl("Unknown or missing format extension: {$path}.");
        }
        $key = implode('/', array_map(rawurldecode(...), explode('/', substr($rawKey, 0, $dot))));

        $url = $this->decodeSpec($spec, $resolver, $key, $format)
            ->withSignature($signature === '_' ? null : $signature);

        if ($this->encode($url) !== $path) {
            throw new InvalidImageUrl("Not the canonical form of this URL: {$path}. It is ".$this->encode($url).'.');
        }

        return $url;
    }

    private function decodeSpec(string $segment, string $resolver, string $key, Format $format): ImageUrl
    {
        $tokens = $segment === '_' ? [] : explode(',', $segment);
        $version = $expires = null;

        // The source version and the expiry come last, in this order.
        if ($tokens !== [] && preg_match('/^exp-(\d{1,12})$/', end($tokens), $matches) === 1) {
            $expires = (int) $matches[1];
            array_pop($tokens);
        }
        if ($tokens !== [] && preg_match('/^v-([A-Za-z0-9_-]{1,64})$/', end($tokens), $matches) === 1) {
            $version = $matches[1];
            array_pop($tokens);
        }

        try {
            if ($tokens !== [] && str_contains($tokens[0], '.')) {
                return $this->decodePreset($tokens, $resolver, $key, $format, $version, $expires);
            }

            return ImageUrl::forSpec($resolver, $key, SpecTokens::decode($tokens, $format), $version, $expires);
        } catch (InvalidImageSpec $exception) {
            throw new InvalidImageUrl($exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @param  non-empty-list<string>  $tokens
     */
    private function decodePreset(array $tokens, string $resolver, string $key, Format $format, ?string $version, ?int $expires): ImageUrl
    {
        [$name, $presetVersion] = explode('.', array_shift($tokens), 2);
        $width = null;

        if ($tokens !== [] && preg_match('/^w(\d{1,9})$/', $tokens[0], $matches) === 1) {
            $width = (int) $matches[1];
            array_shift($tokens);
        }
        if ($tokens !== []) {
            throw new InvalidImageUrl('A preset URL can only add a width: '.implode(',', $tokens).'.');
        }

        return ImageUrl::forPreset($resolver, $key, new PresetReference($name, $presetVersion), $format, $width, $version, $expires);
    }
}
