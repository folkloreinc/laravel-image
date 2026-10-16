<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit;

use Folklore\Image\ImageServiceProvider;
use Folklore\Image\Tests\TestCase;

final class ImageServiceProviderTest extends TestCase
{
    public function test_the_service_provider_is_loaded(): void
    {
        $this->assertTrue($this->app?->providerIsLoaded(ImageServiceProvider::class));
    }
}
