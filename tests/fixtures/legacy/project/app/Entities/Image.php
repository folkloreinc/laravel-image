<?php

declare(strict_types=1);

namespace App\Entities;

// An entity of a 1.x project that builds 1.x URLs.
class Image
{
    public function url(string $path): string
    {
        return rtrim(config('app.image_url', config('app.url')), '/').image()->url($path, ['large']);
    }

    public function thumbnail(string $path): mixed
    {
        return image($path, ['width' => 100]);
    }
}
