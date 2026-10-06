<?php

namespace Folklore\Image\Http;

use Folklore\Image\Contracts\RouteResolver;
use Folklore\Image\Exception\FileMissingException;
use Folklore\Image\Exception\ParseException;
use Folklore\Image\Exception\RestrictionException;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Imagine\Exception\RuntimeException;

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
        } catch (RestrictionException $e) {
            return abort(404);
        } catch (FileMissingException $e) {
            return abort(404);
        } catch (RuntimeException $e) {
            return abort(404);
        }
    }
}
