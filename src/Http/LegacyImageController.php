<?php

declare(strict_types=1);

namespace Folklore\Image\Http;

use Folklore\Image\Compat\LegacyConfig;
use Folklore\Image\Compat\LegacyFilters;
use Folklore\Image\Compat\LegacySources;
use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Exceptions\InvalidImageConfig;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Exceptions\RenderFailed;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\ImageManager;
use Folklore\Image\Rendering\UpscalingPolicy;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Storage\PublicPathStore;
use Folklore\Image\Urls\V1Codec;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the 1.x URLs of the legacy route as 1.x did (docs/decisions/0007-compatibility-layer.md):
 *
 * 1. parses the path with the route's 1.x template (404 when it doesn't match);
 * 2. checks the 1.x restrictions: logged, or 404 when they are enforced;
 * 3. reads the source from the route's 1.x source, with path containment (404);
 * 4. with `cache`, serves the image already written at its path under `cache_path`;
 * 5. renders it with 1.x's upscaling (crops upscale, resizes don't), unless the route says
 *    otherwise, and writes it at its path under `cache_path`, where the web server serves it
 *    statically next time.
 *
 * @internal
 */
final readonly class LegacyImageController
{
    public function __construct(
        private ImageManager $images,
        private Container $container,
    ) {}

    public function __invoke(Request $request): Response
    {
        $route = $request->route();
        $route = $route instanceof Route ? $route : null;
        $options = [...LegacyImageRoute::REGISTRAR_DEFAULTS, ...LegacyConfig::options($route?->getAction('image'))];
        $source = is_string($options['source']) ? $options['source'] : null;

        try {
            $resolver = $this->container->make(LegacySources::class)->resolver($source);
            $codec = $this->container->make(V1Codec::class)->withOptions(is_array($options['pattern']) ? $options['pattern'] : [], $resolver->name());
            $parsed = $codec->parse($this->path($request, $route));
            $pathFilters = array_diff_key($parsed['filters'], array_flip(LegacyFilters::ROUTE_OPTIONS));
            $this->checkRestrictions($request, $options, $pathFilters);
            $upscale = is_bool($options['upscale']) ? $options['upscale'] : $this->container->make(LegacyConfig::class)->upscale();

            $image = $resolver->resolve(ltrim($parsed['path'], '/'));
            $routeFilters = is_array($options['filters']) ? $options['filters'] : [];
            $spec = $this->container->make(LegacyFilters::class)->spec([...$pathFilters, ...$routeFilters], $image);
            $spec = $spec->withFormat($parsed['format'] !== null ? self::format($parsed['format']) : $spec->format->resolve($image->mime));
            if (is_int($options['quality'])) {
                $spec = $spec->with(['quality' => $options['quality']]);
            }

            $store = $options['cache'] === true ? $this->store($options) : null;
            $cachePath = rawurldecode($request->path());
            $cached = $store?->get($cachePath);
            if ($cached !== null) {
                return $this->respond((string) file_get_contents($cached), $spec->format, $options);
            }

            $rendered = $this->renderer($spec, $upscale)->render($image, $spec);
            $store?->put($cachePath, $rendered);
        } catch (InvalidImageUrl|InvalidImageSpec|SourceNotFound $exception) {
            throw new NotFoundHttpException($exception->getMessage(), $exception);
        } catch (RenderFailed $exception) {
            throw new HttpException(415, $exception->getMessage(), $exception);
        }

        return $this->respond($rendered->contents, $rendered->format, $options);
    }

    /**
     * The path of the image, percent-encoded: the route's pattern parameter, whatever comes
     * before it in the route.
     */
    private function path(Request $request, ?Route $route): string
    {
        $parameter = $route?->getAction('image_parameter');
        $value = $route !== null && is_string($parameter) ? $route->parameter($parameter) : null;
        if (! is_string($value)) {
            return ltrim($request->getPathInfo(), '/');
        }

        // The router decoded the parameter: the codec reads it encoded, as V2Codec does.
        return implode('/', array_map(rawurlencode(...), explode('/', $value)));
    }

    /**
     * Logs the restrictions a request breaks, or refuses it when they are enforced.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $filters
     *
     * @throws InvalidImageUrl when they are enforced
     */
    private function checkRestrictions(Request $request, array $options, array $filters): void
    {
        $restrictions = $this->container->make(LegacyRestrictions::class);
        // A preset named in a URL is a size, as the 1.x filter it was made from expanded to one.
        $presets = array_values(array_filter(array_keys($filters), fn (string $name) => $filters[$name] === true && $this->images->presets()->has($name)));
        $violations = $restrictions->violations($options, $filters, $presets);
        if ($violations === []) {
            return;
        }

        if ($restrictions->isEnforced($options)) {
            throw new InvalidImageUrl(implode(' ', $violations));
        }

        $this->container->make(LoggerInterface::class)->warning('Image request does not satisfy the route restrictions; serving it because the restrictions mode is "log".', [
            'route' => $request->route() instanceof Route ? $request->route()->getName() : null,
            'path' => $request->path(),
            'violations' => $violations,
        ]);
    }

    /**
     * The renderer, with the route's upscaling: null is 1.x's, where crops upscale and resizes don't.
     */
    private function renderer(ImageSpec $spec, ?bool $upscale): Renderer
    {
        $renderer = $this->images->renderer();
        $upscale ??= $spec->hasBox() && $spec->fit === Fit::Cover;

        return $renderer instanceof UpscalingPolicy ? $renderer->withUpscale($upscale) : $renderer;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function store(array $options): PublicPathStore
    {
        $directory = $options['cache_path'] ?? (function_exists('public_path') ? public_path() : null);
        if (! is_string($directory) || $directory === '') {
            throw new InvalidImageConfig('The cache_path of the legacy image route is a directory.');
        }

        return new PublicPathStore($directory, is_int($options['cache_mode']) ? $options['cache_mode'] : 0755);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function respond(string $contents, Format $format, array $options): Response
    {
        $response = new Response($contents, 200, [
            'Content-Type' => $format->mime(),
            'Content-Length' => (string) strlen($contents),
        ]);

        $expires = $options['expires'];
        if (is_int($expires) || (is_string($expires) && ctype_digit($expires))) {
            $response->setPublic();
            $response->setMaxAge((int) $expires);
            $response->setExpires((new \DateTimeImmutable)->setTimestamp(time() + (int) $expires));
        }

        foreach (LegacyConfig::options($options['headers']) as $name => $value) {
            if (is_string($value)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private static function format(string $extension): Format
    {
        $extension = strtolower($extension);

        return Format::tryFromExtension($extension === 'jpeg' ? 'jpg' : $extension)
            ?? throw new InvalidImageUrl("Unknown format: {$extension}.");
    }
}
