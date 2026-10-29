<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Compat;

use Folklore\Image\Facades\Image;
use Folklore\Image\Rendering\InterventionRenderer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Intervention\Image\ImageManager;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A 1.x project upgraded without changing anything: its routes file, loaded from
 * `image.routes.map`, serves its 1.x URLs, rendered on each driver with 1.x's upscaling, and
 * writes them under `public/`, where the web server serves them next time.
 */
final class LegacyRenderingTest extends LegacyTestCase
{
    private string $root;

    protected function imageConfig(): array
    {
        return [
            'routes' => ['map' => self::fixturePath('legacy/routes/images.php')],
            'filters' => [...self::CONFIG['filters'], 'thumbnail' => ['width' => 300, 'height' => 300, 'crop' => true]],
        ];
    }

    protected function resolveApplicationConfiguration($app): void
    {
        $this->root = sys_get_temp_dir().'/laravel-image-legacy-'.bin2hex(random_bytes(4));
        (new Filesystem)->makeDirectory("{$this->root}/public/uploads", 0755, true);
        copy(self::fixturePath('images/image.jpg'), "{$this->root}/public/image.jpg");
        copy(self::fixturePath('images/landscape.jpg'), "{$this->root}/public/uploads/photo.jpg");
        $app->usePublicPath("{$this->root}/public");

        parent::resolveApplicationConfiguration($app);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string, string, int, int}>
     */
    public static function renderings(): iterable
    {
        $cases = [
            // The examples of the 1.x README (tests/fixtures/urls/v1-readme.json), from a 300×300 image.
            'a crop' => ['/image-filters(300x300-crop).jpg', 'image/jpeg', 300, 300],
            'a crop position and grayscale' => ['/image-filters(300x300-crop(top_left)-grayscale).jpg', 'image/jpeg', 300, 300],
            'a width and grayscale, as WebP' => ['/image-filters(200x_-grayscale).jpg.webp', 'image/webp', 200, 200],
            'a named filter' => ['/image-filters(thumbnail).jpg', 'image/jpeg', 300, 300],
            // The named sizes of a project, from an 800×500 photo.
            'a box: the image fits inside' => ['/uploads/photo-filters(400x400).jpg', 'image/jpeg', 400, 250],
            'a resize never upscales' => ['/uploads/photo-filters(large).jpg', 'image/jpeg', 800, 500],
            'a crop upscales, as in 1.x' => ['/uploads/photo-filters(facebook).jpg', 'image/jpeg', 1200, 630],
            'a square crop upscales' => ['/uploads/photo-filters(1000x1000-crop).jpg', 'image/jpeg', 1000, 1000],
            'a rotation' => ['/uploads/photo-filters(rotate(90)).jpg', 'image/jpeg', 500, 800],
            'a PNG' => ['/uploads/photo-filters(200x_).jpg.png', 'image/png', 200, 125],
        ];

        foreach (array_keys(self::DRIVERS) as $driver) {
            foreach ($cases as $description => $case) {
                yield "{$driver}: {$description}" => [$driver, ...$case];
            }
        }
    }

    #[DataProvider('renderings')]
    public function test_1x_urls_are_rendered_as_1x_did(string $driver, string $url, string $mime, int $width, int $height): void
    {
        $this->useDriver($driver);

        $response = $this->get($url)->assertOk();

        $contents = (string) $response->getContent();
        $this->assertSame($mime, $response->headers->get('Content-Type'));
        $this->assertSame([$width, $height, $mime], $this->describe($contents));
        $this->assertStringContainsString('max-age=2678400', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($contents, file_get_contents("{$this->root}/public{$url}"), 'The image is written at its path under public/.');
    }

    #[DataProvider('drivers')]
    public function test_grayscale_is_grey(string $driver): void
    {
        $this->useDriver($driver);

        $contents = (string) $this->get('/uploads/photo-filters(200x_-grayscale).jpg.png')->assertOk()->getContent();
        $image = (new ImageManager(InterventionRenderer::makeDriver('gd')))->decodeBinary($contents);

        $this->assertTrue($image->colorAt(100, 60)->isGrayscale());
    }

    #[DataProvider('drivers')]
    public function test_a_route_that_never_upscales(string $driver): void
    {
        $this->useDriver($driver);
        Route::setRoutes(new RouteCollection);
        self::routeImageLegacy('{pattern}', ['upscale' => false, 'cache' => false]);

        $contents = (string) $this->get('/uploads/photo-filters(1000x1000-crop).jpg')->assertOk()->getContent();

        $this->assertSame([500, 500, 'image/jpeg'], $this->describe($contents));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function drivers(): array
    {
        return array_combine(array_keys(self::DRIVERS), array_map(fn (string $driver) => [$driver], array_keys(self::DRIVERS)));
    }

    private function useDriver(string $driver): void
    {
        $this->driverOrSkip($driver);
        $this->config()->set('image.renderer.driver', $driver);
        $this->assertTrue(Image::presets()->has('thumbnail'));
    }

    /**
     * @return array{int, int, string}
     */
    private function describe(string $contents): array
    {
        $size = getimagesizefromstring($contents);
        $this->assertIsArray($size, 'The response is an image.');

        return [$size[0], $size[1], $size['mime']];
    }
}
