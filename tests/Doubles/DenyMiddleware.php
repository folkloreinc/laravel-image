<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Doubles;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Answers 401 to every request, to test that routes take a project's middleware.
 */
final class DenyMiddleware
{
    public function handle(): never
    {
        throw new HttpException(401);
    }
}
