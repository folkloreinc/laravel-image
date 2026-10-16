<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Rendering;

use Folklore\Image\Contracts\Renderer;
use Folklore\Image\Rendering\InterventionRenderer;
use Folklore\Image\Tests\TestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;

final class RendererBindingTest extends TestCase
{
    public function test_the_renderer_is_the_intervention_renderer_with_the_auto_driver(): void
    {
        $renderer = $this->app?->make(Renderer::class);

        $this->assertInstanceOf(InterventionRenderer::class, $renderer);
        $this->assertSame($renderer, $this->app?->make(Renderer::class));
        $this->assertSame('auto', config('image.renderer.driver'));
        $this->assertContains($renderer->driverName(), array_keys(InterventionRenderer::DRIVERS));
    }

    #[DefineEnvironment('useGd')]
    public function test_the_driver_comes_from_the_config(): void
    {
        $renderer = $this->app?->make(Renderer::class);

        $this->assertInstanceOf(InterventionRenderer::class, $renderer);
        $this->assertSame('gd', $renderer->driverName());
    }

    public function test_the_config_has_defaults(): void
    {
        $this->assertSame(['jpeg' => 82, 'webp' => 80, 'avif' => 60], config('image.quality'));
        $this->assertSame('256M', config('image.memory_limit'));
    }

    protected function useGd(Application $app): void
    {
        $app->make(Repository::class)->set('image.renderer.driver', 'gd');
    }
}
