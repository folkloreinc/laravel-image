<?php

namespace Folklore\Image\Handlers;

use Folklore\Image\Contracts\CacheManager;
use Folklore\Image\Contracts\ImageHandlerFactory;
use Folklore\Image\Contracts\RouteResolver;
use Folklore\Image\Contracts\UrlGenerator;
use Folklore\Image\Jobs\CreateUrlCache;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;

class CreateUrlCacheHandler
{
    protected $image;

    protected $router;

    protected $urlGenerator;

    protected $routeResolver;

    protected $cacheManager;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(
        ImageHandlerFactory $image,
        Router $router,
        UrlGenerator $urlGenerator,
        RouteResolver $routeResolver,
        CacheManager $cacheManager
    ) {
        $this->image = $image;
        $this->router = $router;
        $this->urlGenerator = $urlGenerator;
        $this->routeResolver = $routeResolver;
        $this->cacheManager = $cacheManager;
    }

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle(CreateUrlCache $job)
    {
        $route = ! empty($job->route) ? $this->router->getRoutes()->getByName($job->route) : null;
        $routeConfig = ! is_null($route) ? $this->routeResolver->getConfigFromRoute($route) : [];
        $finalFilters = array_merge($job->filters, ! empty($route) ? [
            'pattern' => data_get($routeConfig, 'pattern', []),
        ] : []);
        $finalUrl = $this->urlGenerator->make($job->url, $finalFilters);

        if (! is_null($route)) {
            $method = in_array('POST', $route->methods()) ? 'POST' : 'GET';
            $request = Request::create($finalUrl, $method);
            $image = $this->routeResolver->resolveToImage($route->bind($request));
        } else {
            $parsedPath = $this->urlGenerator->parse($finalUrl);
            $image = $this->image->source()->make($parsedPath['path'], $parsedPath['filters']);
        }

        $cachePath = ! is_null($route) ? data_get($routeConfig, 'cache_path') : null;
        $cacheMode = ! is_null($route) ? data_get($routeConfig, 'cache_mode') : null;
        $this->cacheManager->put($image, $finalUrl, $cachePath, $cacheMode);
    }
}
