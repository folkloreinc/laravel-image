<?php

namespace Folklore\Image\Http;

use Illuminate\Http\Request;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;

use Folklore\Image\Contracts\RouteResolver;
use Folklore\Image\Exception\Exception;
use Folklore\Image\Exception\FileMissingException;
use Folklore\Image\Exception\ParseException;

class ImageController extends BaseController
{
    use DispatchesJobs, ValidatesRequests;

    protected $routeResolver;

    public function __construct(RouteResolver $routeResolver)
    {
        $this->routeResolver = $routeResolver;
    }

    public function serve(Request $request, $path)
    {
        // Increase memory limit and time limit
        ini_set('memory_limit', config('image.memory_limit', '128M'));
        set_time_limit(60);

        // Make the image from the route and return the response
        try {
            return $this->routeResolver->resolveToResponse($request->route());
        } catch (ParseException $e) {
            return abort(404);
        } catch (FileMissingException $e) {
            return abort(404);
        }
    }
}
