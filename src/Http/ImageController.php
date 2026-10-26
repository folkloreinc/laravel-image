<?php

declare(strict_types=1);

namespace Folklore\Image\Http;

use Folklore\Image\Contracts\Preset;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Exceptions\RenderInProgress;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\ImageConfig;
use Folklore\Image\ImageManager;
use Folklore\Image\Rendering\Derivatives;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Storage\PresetSpecKey;
use Folklore\Image\Storage\StoredDerivative;
use Folklore\Image\Urls\ImageUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the URLs of the builder, behind a CDN (docs/decisions/0003-delivery.md,
 * docs/decisions/0005-secure-by-default.md):
 *
 * 1. decodes the path, in its canonical form only (404 otherwise);
 * 2. checks the signature and the expiry (403);
 * 3. resolves the source; a private source needs a signed URL that expires (404);
 * 4. strict mode: a preset at its current version, at one of its widths, or a signed URL (404);
 *    an earlier version of a preset is served only while its derivative is stored;
 * 5. checks the limits (404);
 * 6. serves the stored derivative, or renders and stores it under a lock;
 * 7. streams it, or redirects to it, with caching headers, an ETag and 304 answers.
 */
final readonly class ImageController
{
    public function __construct(
        private ImageManager $images,
        private ImageConfig $config,
        private Derivatives $derivatives,
    ) {}

    public function __invoke(Request $request): Response|StreamedResponse|RedirectResponse
    {
        try {
            $url = $this->images->codec()->decode($this->path($request));
        } catch (InvalidImageUrl $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        }

        $now = $this->images->now();
        $this->verifySignature($url, $now);

        try {
            $source = $this->images->resolvers()->named($url->resolver)->resolveKey($url->key);
            $this->checkPrivacy($url, $source);
            $preset = $this->preset($url);
            $current = $preset === null || $preset->version() === $url->preset?->version;
            $spec = $current ? $this->spec($url, $source, $preset) : $this->earlierSpec($url);
            $size = $source->size();
            $this->config->limits()->check($spec, $size === null ? null : $spec->outputSize($size, $this->config->upscale()));

            $derivative = $current
                ? $this->derivative($url, $source, $spec, $preset)
                : $this->stored($url, $source, $spec, 'This version of the preset is not stored.');
        } catch (SourceNotFound|InvalidImageSpec $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (RenderInProgress $exception) {
            throw new HttpException(503, 'The image is being rendered.', $exception, ['Retry-After' => '1']);
        } catch (RenderFailed $exception) {
            throw new HttpException(415, $exception->getMessage(), $exception);
        }

        return $this->respond($request, $url, $source, $derivative, $now);
    }

    /**
     * The raw path after the route's prefix: the codec reads it percent-encoded, as the URL
     * was built. The route's own segments are dropped, whatever group prefix it is in.
     */
    private function path(Request $request): string
    {
        $route = $request->route();
        $uri = $route instanceof Route ? $route->uri() : '{path}';
        $before = substr($uri, 0, (int) strpos($uri, '{path}'));
        $prefixSegments = $before === '' ? 0 : count(explode('/', rtrim($before, '/')));

        return implode('/', array_slice(explode('/', ltrim($request->getPathInfo(), '/')), $prefixSegments));
    }

    private function verifySignature(ImageUrl $url, int $now): void
    {
        if ($url->signature === null) {
            return;
        }
        if (! $this->images->signer()->verify($this->images->codec()->payload($url), $url->signature)) {
            throw new HttpException(403, 'Invalid image signature.');
        }
        if ($url->hasExpired($now)) {
            throw new HttpException(403, 'This image URL has expired.');
        }
    }

    /**
     * Private sources are only served through signed URLs that expire.
     */
    private function checkPrivacy(ImageUrl $url, ImageSource $source): void
    {
        if ($source->isPrivate() && ($url->signature === null || $url->expires === null)) {
            throw new SourceNotFound('A private source is only served through a signed URL that expires.');
        }
    }

    /**
     * The preset a preset URL names, at any of its versions, or null for a free spec.
     */
    private function preset(ImageUrl $url): ?Preset
    {
        if ($url->preset === null) {
            return null;
        }

        return $this->images->presets()->find($url->preset->name)
            ?? throw new InvalidImageSpec("No preset {$url->preset->toString()}.");
    }

    /**
     * The spec of the URL: a preset at its current version and one of its widths, or a free
     * spec, which strict mode only serves signed.
     */
    private function spec(ImageUrl $url, ImageSource $source, ?Preset $preset): ImageSpec
    {
        if ($preset === null) {
            if ($url->signature === null && $this->config->strict()) {
                throw new InvalidImageSpec('Strict mode only serves presets, and signed URLs.');
            }

            return $url->spec ?? new ImageSpec(format: $url->format);
        }

        $spec = $preset->spec($source);
        if ($url->width !== null) {
            // The preset's own width has no width in its URL: one spelling per URL.
            if ($url->width === $spec->width || ! in_array($url->width, $preset->widths(), true)) {
                throw new InvalidImageSpec("{$url->width} is not one of the widths of the {$preset->name()} preset.");
            }
            $spec = $spec->atWidth($url->width);
        }

        return $spec->withFormat($url->format);
    }

    /**
     * The spec of an earlier version of a preset, as the store recorded it when a derivative of
     * this version of the source was rendered (docs/decisions/0012-earlier-preset-versions.md).
     * Its widths are not known any more: the derivative must be stored.
     */
    private function earlierSpec(ImageUrl $url): ImageSpec
    {
        $preset = $url->preset ?? throw new InvalidImageSpec('Not a preset URL.');
        $spec = $this->derivatives->presetSpec(new PresetSpecKey($url->resolver, $url->key, $url->version, $preset))
            ?? throw new InvalidImageSpec("No preset {$preset->toString()}.");

        if ($url->width !== null) {
            if ($url->width === $spec->width) {
                throw new InvalidImageSpec("{$url->width} is the width of the {$preset->name} preset itself.");
            }
            $spec = $spec->atWidth($url->width);
        }

        return $spec->withFormat($url->format);
    }

    /**
     * The derivative of the source's current version, rendered and stored when needed. A URL
     * of another version of the source is only served while its derivative is stored: the
     * original it was rendered from is gone.
     */
    private function derivative(ImageUrl $url, ImageSource $source, ImageSpec $spec, ?Preset $preset): StoredDerivative
    {
        if ($url->version === $source->version) {
            return $this->derivatives->fetch($source, $spec, $preset);
        }

        return $this->stored($url, $source, $spec, 'This version of the source is gone.');
    }

    /**
     * A derivative already stored, without rendering it.
     */
    private function stored(ImageUrl $url, ImageSource $source, ImageSpec $spec, string $missing): StoredDerivative
    {
        return $this->derivatives->find(new DerivativeKey($source->resolver, $source->key, $url->version, $spec))
            ?? throw new SourceNotFound($missing);
    }

    private function respond(Request $request, ImageUrl $url, ImageSource $source, StoredDerivative $derivative, int $now): Response|StreamedResponse|RedirectResponse
    {
        $headers = ['Cache-Control' => $this->cacheControl($url, $source, $now)];

        if ($this->config->serve() === 'redirect' && ! $source->isPrivate()) {
            $location = $this->derivatives->url($derivative->key);
            if ($location !== null) {
                return new RedirectResponse($location, 302, $headers);
            }
        }

        $headers += [
            'Content-Type' => $derivative->mime(),
            'Content-Length' => (string) $derivative->length,
            'X-Content-Type-Options' => 'nosniff',
        ];

        $notModified = new Response('', 200, $headers);
        $notModified->setEtag($derivative->etag());
        if ($derivative->lastModified !== null) {
            $notModified->setLastModified((new \DateTimeImmutable)->setTimestamp($derivative->lastModified));
        }
        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        $response = new StreamedResponse(function () use ($derivative): void {
            $stream = $derivative->stream();
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $notModified->headers->all());

        return $response;
    }

    /**
     * Versioned URLs never change: they are cached for `image.delivery.max_age` and marked
     * immutable. URLs that expire are cached no longer than their remaining lifetime, and
     * private sources only by the browser.
     */
    private function cacheControl(ImageUrl $url, ImageSource $source, int $now): string
    {
        $maxAge = $this->config->maxAge();
        if ($url->expires !== null) {
            $maxAge = max(0, min($maxAge, $url->expires - $now));
        }

        return implode(', ', array_filter([
            $source->isPrivate() ? 'private' : 'public',
            "max-age={$maxAge}",
            $url->version !== null ? 'immutable' : null,
        ]));
    }
}
