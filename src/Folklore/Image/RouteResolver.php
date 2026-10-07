<?php

namespace Folklore\Image;

use Folklore\Image\Contracts\ImageHandlerFactory as ImageHandlerFactoryContract;
use Folklore\Image\Contracts\RouteResolver as RouteResolverContract;
use Folklore\Image\Contracts\UrlGenerator as UrlGeneratorContract;
use Folklore\Image\Exception\RestrictionException;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class RouteResolver implements RouteResolverContract
{
    /**
     * Options a URL can't set: only the route (or the config) decides on them.
     */
    protected const ROUTE_ONLY_OPTIONS = ['upscale', 'auto_orient', 'memory_limit'];

    protected $image;

    protected $urlGenerator;

    protected $restrictions;

    public function __construct(
        ImageHandlerFactoryContract $image,
        UrlGeneratorContract $urlGenerator,
        ?RouteRestrictions $restrictions = null
    ) {
        $this->image = $image;
        $this->urlGenerator = $urlGenerator;
        $this->restrictions = $restrictions ?? app(RouteRestrictions::class);
    }

    public function resolveToImage(Route $route)
    {
        $path = $this->getPathFromRoute($route);
        $config = $this->getConfigFromRoute($route);
        $source = data_get($config, 'source');
        $urlConfig = data_get($config, 'pattern', []);
        $routeFilters = data_get($config, 'filters', []);

        // Parse the path
        $parseData = $this->urlGenerator->parse($path, $urlConfig);
        $path = $parseData['path'];
        $pathFilters = $parseData['filters'];
        $filters = $this->mergeFilters($config, $pathFilters, $routeFilters);

        // Get the image
        $handler = $this->image->source($source);

        return $handler->make($path, $filters);
    }

    public function resolveToResponse(Route $route)
    {
        $path = $this->getPathFromRoute($route);
        $config = $this->getConfigFromRoute($route);

        $source = data_get($config, 'source');
        $quality = data_get($config, 'quality');
        $expires = data_get($config, 'expires', null);
        $urlConfig = data_get($config, 'pattern', []);
        $routeFilters = data_get($config, 'filters', []);

        // Parse the path
        $requestPath = $path;
        $parseData = $this->urlGenerator->parse($path, $urlConfig);
        $path = $parseData['path'];
        $pathFilters = $parseData['filters'];
        $filters = $this->mergeFilters($config, $pathFilters, $routeFilters);

        // Check the filters from the URL against the route restrictions
        // (options only the route decides on are ignored, so they aren't checked either)
        $this->checkRestrictions($route, $config, $requestPath, Arr::except($pathFilters, self::ROUTE_ONLY_OPTIONS));

        // Get the image
        $handler = $this->image->source($source);
        $image = $handler->make($path, $filters);
        $mime = ! is_null($image) ? $image->metadata()['file.MimeType'] : null;
        $handler = $this->image->source($source);

        $response = response()
            ->image($image)
            ->setQuality($quality !== null ? (int) $quality : null)
            ->setFormat(data_get($parseData, 'format') ?? $mime ?? $handler->format($path))
            ->setExpiresIn($expires);

        $headers = data_get($config, 'headers', []);
        if (is_array($headers) && count($headers) > 0) {
            $response->withHeaders($headers);
        }

        return $response;
    }

    /**
     * Log or reject a request that breaks the route restrictions.
     *
     * @throws RestrictionException When the restrictions are enforced
     */
    protected function checkRestrictions(Route $route, array $config, string $path, array $filters): void
    {
        $violations = $this->restrictions->violations($config, $filters);
        if (count($violations) === 0) {
            return;
        }

        if ($this->restrictions->isEnforced($config)) {
            throw new RestrictionException(implode(' ', $violations));
        }

        Log::warning('Image request does not satisfy the route restrictions; serving it because the restrictions mode is "log".', [
            'route' => $route->getName(),
            'path' => $path,
            'violations' => $violations,
        ]);
    }

    /**
     * Merge the filters from the URL with the route's own filters and options.
     *
     * Only the route decides on upscaling, orientation and memory: `upscale`,
     * `auto_orient` and `memory_limit` filters in the URL are ignored.
     */
    protected function mergeFilters(array $config, array $pathFilters, array $routeFilters): array
    {
        $filters = array_merge(Arr::except($pathFilters, self::ROUTE_ONLY_OPTIONS), $routeFilters);
        if (data_get($config, 'upscale') !== null) {
            $filters['upscale'] = $config['upscale'];
        }
        if (data_get($config, 'auto_orient') !== null) {
            $filters['auto_orient'] = $config['auto_orient'];
        }

        return $filters;
    }

    public function getPathFromRoute(Route $route)
    {
        return data_get($route->parameters(), 'image_pattern', $route->uri());
    }

    public function getConfigFromRoute(Route $route)
    {
        return data_get($route->getAction(), 'image', []);
    }
}
