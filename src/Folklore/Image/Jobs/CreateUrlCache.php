<?php

namespace Folklore\Image\Jobs;

use Folklore\Image\Handlers\CreateUrlCacheHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;

class CreateUrlCache implements ShouldQueue
{
    public $url;

    public $filters = [];

    public $route = null;

    /**
     * Create a new job instance.
     *
     * @param  string  $url
     * @param  array  $filters
     * @param  string  $route
     * @return void
     */
    public function __construct($url, $filters = [], $route = null)
    {
        $this->url = $url;
        $this->filters = $filters;
        $this->route = $route;
    }

    /**
     * Handle the job with the handler
     *
     * @return void
     */
    public function handle(Container $container)
    {
        return $container->make(CreateUrlCacheHandler::class)->handle($this);
    }
}
