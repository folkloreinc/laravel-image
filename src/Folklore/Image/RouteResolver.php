<?php
namespace Folklore\Image;

use Illuminate\Routing\Route;
use Folklore\Image\Contracts\ImageHandlerFactory as ImageHandlerFactoryContract;
use Folklore\Image\Contracts\UrlGenerator as UrlGeneratorContract;
use Folklore\Image\Contracts\RouteResolver as RouteResolverContract;

class RouteResolver implements RouteResolverContract
{
    protected $image;

    protected $urlGenerator;

    public function __construct(ImageHandlerFactoryContract $image, UrlGeneratorContract $urlGenerator)
    {
        $this->image = $image;
        $this->urlGenerator = $urlGenerator;
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
        $filters = array_merge($pathFilters, $routeFilters);

        // Get the image
        $handler = $this->image->source($source);
        return $handler->make($path, $filters);
    }

    public function resolveToResponse(Route $route)
    {
        $path = $this->getPathFromRoute($route);
        $config = $this->getConfigFromRoute($route);

        $source = data_get($config, 'source');
        $quality = (float) data_get($config, 'quality', 100);
        $expires = data_get($config, 'expires', null);
        $urlConfig = data_get($config, 'pattern', []);
        $routeFilters = data_get($config, 'filters', []);

        // Parse the path
        $parseData = $this->urlGenerator->parse($path, $urlConfig);
        $path = $parseData['path'];
        $pathFilters = $parseData['filters'];
        $filters = array_merge($pathFilters, $routeFilters);

        // Get the image
        $handler = $this->image->source($source);
        $image = $handler->make($path, $filters);
        $mime = !is_null($image) ? $image->metadata()['file.MimeType'] : null;
        $handler = $this->image->source($source);

        return response()
            ->image($image)
            ->setQuality($quality)
            ->setFormat(!is_null($mime) ? $mime : $handler->format($path))
            ->setExpiresIn($expires);
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
