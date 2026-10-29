<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Compat;

use Folklore\Image\Facades\Image;
use Folklore\Image\Http\LegacyImageController;
use Folklore\Image\Http\LegacyRestrictions;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Testing\ImageFake;
use Folklore\Image\Urls\V1Codec;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The legacy route, through real HTTP requests, with the fake renderer: 1.x URLs are decoded
 * with the project's 1.x config, checked against the 1.x restrictions, and written under the
 * route's cache path.
 */
final class LegacyRouteTest extends LegacyTestCase
{
    private ImageFake $fake;

    private string $originals;

    private string $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $root = sys_get_temp_dir().'/laravel-image-legacy-'.bin2hex(random_bytes(4));
        $this->originals = "{$root}/originals";
        $this->cache = "{$root}/public";
        (new Filesystem)->makeDirectory("{$this->originals}/uploads", 0755, true);
        copy(self::fixturePath('images/image.jpg'), "{$this->originals}/image.jpg");
        copy(self::fixturePath('images/landscape.jpg'), "{$this->originals}/uploads/photo.jpg");
        $this->config()->set('image.sources.public.path', $this->originals);

        $this->fake = Image::fake();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(dirname($this->originals));

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function servedUrls(): iterable
    {
        yield 'a crop' => ['/image-filters(300x300-crop).jpg', 'w300,h300.jpeg'];
        yield 'a crop position and an operation' => ['/image-filters(300x300-crop(top_left)-grayscale).jpg', 'w300,h300,fp-0-0,grayscale.jpeg'];
        yield 'a width, as WebP' => ['/image-filters(200x_-grayscale).jpg.webp', 'w200,grayscale.webp'];
        yield 'a size, without filters' => ['/uploads/photo.jpg', '_.jpeg'];
        yield 'a box' => ['/uploads/photo-filters(400x400).jpg', 'w400,h400,fit-inside.jpeg'];
        yield 'a named size' => ['/uploads/photo-filters(large).jpg', 'w1400,h1400,fit-inside.jpeg'];
        yield 'a named size, cropped' => ['/uploads/photo-filters(facebook).jpg.webp', 'w1200,h630.webp'];
        yield 'a rotation' => ['/uploads/photo-filters(rotate(-90)).jpg', 'r-270.jpeg'];
    }

    #[DataProvider('servedUrls')]
    public function test_1x_urls_are_rendered_and_written_at_their_path(string $url, string $spec): void
    {
        self::routeImageLegacy('{pattern}', ['cache_path' => $this->cache]);

        $response = $this->get($url)->assertOk();

        $this->assertSame(str_ends_with($spec, '.webp') ? 'image/webp' : 'image/jpeg', $response->headers->get('Content-Type'));
        Image::assertRendered(fn (ImageSpec $rendered) => $rendered->key() === $spec);
        $this->assertFileExists($this->cache.$url, 'The image is written where the web server serves it.');
    }

    public function test_a_written_image_is_served_without_rendering_it_again(): void
    {
        self::routeImageLegacy('{pattern}', ['cache_path' => $this->cache]);
        $this->get('/image-filters(300x300-crop).jpg')->assertOk();
        file_put_contents("{$this->cache}/image-filters(300x300-crop).jpg", 'written');

        $this->assertSame('written', $this->get('/image-filters(300x300-crop).jpg')->assertOk()->getContent());
        $this->assertCount(1, $this->fake->renderedImages());
    }

    public function test_without_cache_nothing_is_written(): void
    {
        self::routeImageLegacy('{pattern}', ['cache' => false, 'cache_path' => $this->cache]);

        $this->get('/image-filters(300x300-crop).jpg')->assertOk();
        $this->get('/image-filters(300x300-crop).jpg')->assertOk();

        $this->assertDirectoryDoesNotExist($this->cache);
        $this->assertCount(2, $this->fake->renderedImages());
    }

    public function test_responses_have_the_expiry_and_headers_of_the_route(): void
    {
        self::routeImageLegacy('{pattern}', ['cache' => false, 'expires' => 3600, 'headers' => ['X-Robots-Tag' => 'noindex']]);

        $response = $this->get('/image-filters(300x300-crop).jpg')->assertOk();

        $this->assertStringContainsString('max-age=3600', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertNotNull($response->headers->get('Expires'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
    }

    public function test_the_filters_and_quality_of_the_route_apply_to_every_image(): void
    {
        self::routeImageLegacy('thumbnails/{pattern}', ['cache' => false, 'filters' => ['width' => 100, 'height' => 100, 'crop' => true], 'quality' => 60]);

        $this->get('/thumbnails/uploads/photo-filters(grayscale).jpg')->assertOk();

        Image::assertRendered(fn (ImageSpec $spec) => $spec->key() === 'w100,h100,grayscale,q60.jpeg');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function missingUrls(): iterable
    {
        yield 'a missing image' => ['/missing-filters(300x300).jpg'];
        yield 'outside the source' => ['/uploads/../../secret-filters(300x300).jpg'];
        yield 'a malformed filter' => ['/image-filters(rotate(9)0)).jpg'];
        yield 'a rotation 2.x does not do' => ['/image-filters(rotate(45)).jpg'];
        yield 'not an image' => ['/image.txt'];
    }

    #[DataProvider('missingUrls')]
    public function test_what_1x_answered_404_to_answers_404(string $url): void
    {
        self::routeImageLegacy('{pattern}', ['cache_path' => $this->cache]);

        $this->get($url)->assertNotFound();
        Image::assertNothingRendered();
        $this->assertDirectoryDoesNotExist($this->cache);
    }

    public function test_restrictions_are_logged_and_served_in_log_mode(): void
    {
        $log = new TestHandler;
        $this->config()->set('logging.default', 'test');
        $this->config()->set('logging.channels.test', ['driver' => 'custom', 'via' => fn () => new Logger('test', [$log])]);
        self::routeImageLegacy('{pattern}', ['cache' => false, 'allow_size' => false]);

        $this->get('/image-filters(300x300-crop).jpg')->assertOk();

        $this->assertTrue($log->hasWarningThatContains('does not satisfy the route restrictions'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, bool}>
     */
    public static function restrictions(): iterable
    {
        yield 'a size, not allowed' => [['allow_size' => false], '/image-filters(300x300).jpg', false];
        yield 'filters, not allowed' => [['allow_filters' => false], '/image-filters(grayscale).jpg', false];
        yield 'filters, not allowed, with a size' => [['allow_filters' => false], '/image-filters(300x300-crop).jpg', true];
        yield 'a filter allowed' => [['allow_filters' => ['grayscale']], '/image-filters(grayscale).jpg', true];
        yield 'a filter not allowed' => [['allow_filters' => ['grayscale']], '/image-filters(negative).jpg', false];
        yield 'a filter refused' => [['disallow_filters' => ['negative']], '/image-filters(negative).jpg', false];
        yield 'a width over the limit' => [['restrictions' => ['max_width' => 1000]], '/image-filters(2000x_).jpg', false];
        yield 'too many pixels' => [['restrictions' => ['max_pixels' => 10_000]], '/image-filters(200x200).jpg', false];
        yield 'too many filters' => [['restrictions' => ['max_filters' => 1]], '/image-filters(grayscale-negative).jpg', false];
        yield 'within the limits' => [['restrictions' => ['max_width' => 1000]], '/image-filters(300x_).jpg', true];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    #[DataProvider('restrictions')]
    public function test_restrictions_are_enforced_in_enforce_mode(array $options, string $url, bool $served): void
    {
        $this->config()->set('image.restrictions.mode', 'enforce');
        $this->app?->forgetInstance(LegacyRestrictions::class);
        self::routeImageLegacy('{pattern}', ['cache' => false, ...$options]);

        $this->get($url)->assertStatus($served ? 200 : 404);
    }

    public function test_the_1x_router_macro_registers_the_legacy_route_with_1x_defaults(): void
    {
        $route = app(Router::class)->macroCall('image', ['{pattern}', ['as' => 'image', 'cache_path' => $this->cache]]);

        $this->assertInstanceOf(RoutingRoute::class, $route);
        $this->assertSame(LegacyImageController::class, $route->getControllerClass());
        $this->get('/image-filters(300x300-crop).jpg')->assertOk();
        $this->assertDirectoryDoesNotExist($this->cache, '1.x routes cached only with cache: true.');
        $this->assertStringContainsString('$router->image($path, $config) is deprecated', implode("\n", $this->deprecations()));
    }

    public function test_a_route_reads_another_1x_source(): void
    {
        Storage::fake('public')->put('photos/photo.jpg', (string) file_get_contents(self::fixturePath('images/image.jpg')));
        self::routeImageLegacy('cloud/{pattern}', ['source' => 'cloud', 'cache' => false]);

        $this->get('/cloud/photos/photo-filters(100x_).jpg')->assertOk();
        $this->get('/cloud/image-filters(100x_).jpg')->assertNotFound();

        Image::assertRendered(fn (ImageSpec $spec, ImageSource $source) => $source->resolver === 'cloud' && $source->key === 'photos/photo.jpg');
    }

    public function test_a_route_with_the_project_template(): void
    {
        $this->config()->set('image.url.format', '{dirname}/{filters}{basename}.{extension}');
        $this->config()->set('image.url.filters_format', 'filters/{filter}/');
        $this->config()->set('image.url.filter_separator', '/');
        $this->app?->forgetInstance(V1Codec::class);
        self::routeImageLegacy('{pattern}', ['cache_path' => $this->cache]);

        $this->get('/uploads/filters/large/photo.jpg')->assertOk();
        $this->get('/uploads/filters/300x300/crop/photo.jpg')->assertOk();

        Image::assertRendered(fn (ImageSpec $spec) => $spec->key() === 'w1400,h1400,fit-inside.jpeg');
        Image::assertRendered(fn (ImageSpec $spec) => $spec->key() === 'w300,h300.jpeg');
        $this->assertFileExists("{$this->cache}/uploads/filters/large/photo.jpg");
    }
}
