<?php

namespace Folklore\Image\Http;

use Closure;
use Folklore\Image\Contracts\CacheManager;
use Illuminate\Http\Request;

class CacheMiddleware
{
    protected $cacheManager;

    public function __construct(CacheManager $cacheManager)
    {
        $this->cacheManager = $cacheManager;
    }

    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $path = $request->path();
        $route = $request->route();
        $routeConfig = $route ? data_get($route->getAction(), 'image', []) : [];
        $cachePath = data_get($routeConfig, 'cache_path');
        $cacheMode = data_get($routeConfig, 'cache_mode', 0755);

        // Get the response
        $response = $next($request);

        // Return the response if not successful
        if ($response->status() !== 200) {
            return $response;
        }

        // If it's an ImageResponse, save the image from the Image object.
        // Otherwise, ignore it.
        if ($response instanceof ImageResponse) {
            $image = $response->getImage();
            $path = $this->cacheManager->put($image, $path, $cachePath, $cacheMode);
            $response->setImagePath($path);
        }

        return $response;
    }
}
