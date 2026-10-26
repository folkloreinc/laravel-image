<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Http;

use Folklore\Image\Contracts\DerivativeStore;
use Folklore\Image\Contracts\Signer;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Facades\Image;
use Folklore\Image\Rendering\RenderedImage;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ArrayPreset;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Ratio;
use Folklore\Image\Specs\Transform;
use Folklore\Image\Storage\DerivativeKey;
use Folklore\Image\Testing\ImageFake;
use Folklore\Image\Testing\InMemoryDerivativeStore;
use Folklore\Image\Tests\Doubles\ArraySourceResolver;
use Folklore\Image\Tests\Doubles\DenyMiddleware;
use Folklore\Image\Tests\Feature\ImageTestCase;
use Folklore\Image\Urls\ImageUrl;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The image route, through real HTTP requests, with the fake renderer and the in-memory store.
 */
final class ImageRouteTest extends ImageTestCase
{
    private ImageFake $fake;

    private InMemoryDerivativeStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
        $this->fake = Image::fake();
        $this->store = new InMemoryDerivativeStore('https://bucket.test/derivatives');
        $this->app?->instance(DerivativeStore::class, $this->store);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function defineRoutes($router): void
    {
        self::routeImage();
    }

    public function test_a_preset_url_is_rendered_once_and_cached_for_a_year(): void
    {
        $url = Image::for($this->photo)->preset('card')->webp()->url();

        $response = $this->get($url)->assertOk();
        $this->assertSame('image/webp', $response->headers->get('Content-Type'));
        $this->assertCacheControl($response, ['public', 'max-age=31536000', 'immutable']);
        $this->assertNotNull($response->headers->get('ETag'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $this->get($url)->assertOk();
        $this->assertCount(1, $this->fake->renderedImages(), 'The second request reads the stored derivative.');
        Image::assertRendered(fn (ImageSpec $spec) => $spec->key() === 'w800,ar-16-9.webp');
        $this->assertSame(['array/photos/2026/photo.jpg/8c1f/w800,ar-16-9.webp'], $this->store->paths());
    }

    public function test_a_stored_derivative_is_streamed(): void
    {
        $spec = (new ImageSpec(width: 800, ratio: new Ratio(16, 9)))->withFormat(Format::Jpeg);
        $this->store->put(DerivativeKey::for($this->photo, $spec), new RenderedImage('stored-bytes', Format::Jpeg, 800, 450));

        $response = $this->get(Image::for($this->photo)->preset('card')->url())->assertOk();

        $this->assertSame('stored-bytes', $response->streamedContent());
        $this->assertSame('12', $response->headers->get('Content-Length'));
        Image::assertNothingRendered();
    }

    public function test_a_matching_etag_answers_304(): void
    {
        $url = Image::for($this->photo)->preset('card')->url();
        $etag = (string) $this->get($url)->headers->get('ETag');

        $response = $this->get($url, ['If-None-Match' => $etag]);

        $response->assertStatus(304);
        $this->assertSame('', $response->getContent());
        $this->assertCacheControl($response, ['public', 'max-age=31536000', 'immutable']);
        $this->get($url, ['If-None-Match' => '"other"'])->assertOk();
    }

    public function test_a_preset_at_one_of_its_widths(): void
    {
        $this->get(Image::for($this->photo)->preset('card')->width(400)->avif()->url())->assertOk();

        Image::assertRendered(fn (ImageSpec $spec) => $spec->key() === 'w400,ar-16-9.avif');
    }

    public function test_presets_are_only_rendered_at_their_current_version_and_widths(): void
    {
        $version = ArrayPreset::fromArray('card', self::CARD)->version();

        $this->get("/img/_/card.{$version},v-8c1f/array/photos/2026/photo.jpg.jpg")->assertOk();
        $this->get('/img/_/card.000000,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertNotFound();
        $this->get("/img/_/missing.{$version},v-8c1f/array/photos/2026/photo.jpg.jpg")->assertNotFound();
        $this->get("/img/_/card.{$version},w500,v-8c1f/array/photos/2026/photo.jpg.jpg")->assertNotFound();
        $this->get("/img/_/card.{$version},w800,v-8c1f/array/photos/2026/photo.jpg.jpg")->assertNotFound();
        $this->assertCount(1, $this->fake->renderedImages());
    }

    public function test_an_earlier_version_of_a_preset_is_served_while_its_derivative_is_stored(): void
    {
        $old = Image::for($this->photo)->preset('card')->webp()->url();
        $oldAtWidth = Image::for($this->photo)->preset('card')->width(400)->webp()->url();
        $neverRendered = Image::for($this->photo)->preset('card')->width(1200)->webp()->url();
        $contents = $this->get($old)->assertOk()->streamedContent();
        $this->get($oldAtWidth)->assertOk();

        Image::presets()->register(ArrayPreset::fromArray('card', ['width' => 600, 'ratio' => '1:1', 'widths' => [300, 600]]));
        $new = Image::for($this->photo)->preset('card')->webp()->url();
        $this->assertNotSame($old, $new);

        $response = $this->get($old)->assertOk();
        $this->assertSame($contents, $response->streamedContent());
        $this->assertCacheControl($response, ['public', 'max-age=31536000', 'immutable']);
        $this->get($oldAtWidth)->assertOk();
        $this->assertCount(2, $this->fake->renderedImages(), 'Earlier versions are served from the store.');

        // Nothing is rendered for an earlier version: a derivative that isn't stored answers 404.
        $this->get($neverRendered)->assertNotFound();
        $this->get(str_replace('.webp', '.avif', $old))->assertNotFound();

        $this->get($new)->assertOk();
        Image::assertRendered(fn (ImageSpec $spec) => $spec->key() === 'w600,ar-1-1.webp');
    }

    public function test_an_earlier_version_of_a_preset_without_its_spec_recorded_answers_404(): void
    {
        // A derivative stored without its preset spec, as alpha 1 stored them.
        $spec = ArrayPreset::fromArray('card', self::CARD)->spec($this->photo)->withFormat(Format::Jpeg);
        $this->store->put(new DerivativeKey('array', 'photos/2026/photo.jpg', '8c1f', $spec), new RenderedImage('stored', Format::Jpeg, 800, 450));
        $old = Image::for($this->photo)->preset('card')->url();
        $this->get($old)->assertOk();

        Image::presets()->register(ArrayPreset::fromArray('card', ['width' => 600]));

        $this->get($old)->assertNotFound();
        Image::assertNothingRendered();
    }

    public function test_earlier_versions_of_a_preset_are_cleared_with_the_source(): void
    {
        $old = Image::for($this->photo)->preset('card')->url();
        $this->get($old)->assertOk();
        Image::presets()->register(ArrayPreset::fromArray('card', ['width' => 600]));

        $this->store->clear($this->photo);

        $this->get($old)->assertNotFound();
    }

    public function test_non_canonical_paths_are_refused(): void
    {
        $this->config()->set('image.strict', false);

        $this->get('/img/_/w300,h200,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertOk();
        $this->get('/img/_/h200,w300,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertNotFound();
        $this->get('/img/_/w300,h200,v-8c1f/array/photos/2026/photo.jpg.jpeg')->assertNotFound();
        $this->get('/img/_/w300,h200,fit-cover,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertNotFound();
        $this->get('/img/_/w300,h200,v-8c1f/array/photos%2F2026/photo.jpg.jpg')->assertNotFound();
        $this->get('/img/garbage')->assertNotFound();
    }

    public function test_strict_mode_only_serves_free_specs_signed(): void
    {
        $path = '/img/_/w300,v-8c1f/array/photos/2026/photo.jpg.jpg';
        $this->get($path)->assertNotFound();

        $this->get(Image::for($this->photo)->width(300)->signed(expires: false)->url())->assertOk();

        $this->config()->set('image.strict', false);
        $this->get($path)->assertOk();
    }

    public function test_limits_are_enforced_signed_or_not(): void
    {
        $this->config()->set('image.strict', false);
        $this->config()->set('image.upscale', true);

        $this->get('/img/_/w5000,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertOk();
        $this->get('/img/_/w5001,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertNotFound();
        $this->get('/img/_/w5000,h5001,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertNotFound();
        $this->get('/img/_/w5000,h5000,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertOk();
        $this->config()->set('image.limits.max_pixels', 24_999_999);
        $this->get('/img/_/w5000,h5000,v-8c1f/array/photos/2026/photo.jpg.jpg')->assertNotFound();

        $this->config()->set('image.limits.max_width', null);
        $url = Image::for($this->photo)->width(6000)->signed()->url();
        $this->config()->set('image.limits.max_width', 5000);
        $this->get($url)->assertNotFound();
    }

    public function test_signatures_are_verified(): void
    {
        $url = Image::for($this->photo)->width(300)->signed(expires: false)->url();

        $this->get(str_replace('/w300,', '/w301,', $url))->assertForbidden();
        $this->get(preg_replace('#/img/[^/]+/#', '/img/AAAAAAAAAAAAAAAAAAAAAA/', $url) ?? '')->assertForbidden();

        $this->config()->set('image.signing.keys', ['new-key', 'test-signing-key']);
        $this->app?->forgetInstance(Signer::class);
        $this->get($url)->assertOk();
    }

    public function test_signed_urls_expire_and_are_cached_no_longer_than_their_lifetime(): void
    {
        $url = Image::for($this->photo)->width(300)->signed(600)->url();

        $this->assertCacheControl($this->get($url)->assertOk(), ['public', 'max-age=600', 'immutable']);

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_500));
        $this->assertCacheControl($this->get($url)->assertOk(), ['public', 'max-age=100', 'immutable']);

        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_600));
        $this->get($url)->assertForbidden();
    }

    public function test_private_sources_are_only_served_through_signed_urls_that_expire(): void
    {
        $preset = ArrayPreset::fromArray('card', self::CARD)->reference();
        $codec = $this->app?->make(UrlCodec::class);
        $this->assertInstanceOf(UrlCodec::class, $codec);
        $unsigned = ImageUrl::forSource($this->private, $preset, Format::Jpeg);
        $forever = $unsigned->withSignature($this->signer()->sign($codec->payload($unsigned)));

        $this->get('/img/'.$codec->encode($unsigned))->assertNotFound();
        $this->get('/img/'.$codec->encode($forever))->assertNotFound();

        $response = $this->get(Image::for($this->private)->preset('card')->url())->assertOk();
        $this->assertCacheControl($response, ['private', 'max-age=3600', 'immutable']);
    }

    public function test_unknown_sources_and_resolvers_answer_404(): void
    {
        $this->config()->set('image.strict', false);

        $this->get('/img/_/w300,v-8c1f/array/missing.jpg.jpg')->assertNotFound();
        $this->get('/img/_/w300,v-8c1f/media/photos/2026/photo.jpg.jpg')->assertNotFound();
    }

    public function test_another_version_of_the_source_is_only_served_while_it_is_stored(): void
    {
        $old = Image::for(new ImageSource('array', 'photos/2026/photo.jpg', '0ld', width: 1200, height: 800, mime: 'image/jpeg'))->preset('card')->url();

        $this->get($old)->assertNotFound();
        Image::assertNothingRendered();

        $spec = ArrayPreset::fromArray('card', self::CARD)->spec($this->photo)->withFormat(Format::Jpeg);
        $this->store->put(new DerivativeKey('array', 'photos/2026/photo.jpg', '0ld', $spec), new RenderedImage('old', Format::Jpeg, 800, 450));
        $this->assertSame('old', $this->get($old)->assertOk()->streamedContent());
    }

    public function test_editing_the_transform_or_the_focal_point_of_a_source_gives_new_urls(): void
    {
        $edited = fn (array $transform) => new ImageSource('array', 'edited.jpg', '8c1f', width: 1200, height: 800, mime: 'image/jpeg', transform: Transform::fromArray($transform));
        $first = $edited(['crop' => ['x' => 0, 'y' => 0, 'width' => 600, 'height' => 400], 'focalPoint' => ['x' => 0.2, 'y' => 0.3]]);
        Image::resolver(new ArraySourceResolver($first));
        $url = Image::for($first)->preset('card')->url();

        $this->assertMatchesRegularExpression('#,v-8c1f-[0-9a-f]{6}/array/edited\.jpg\.jpg$#', $url);
        $response = $this->get($url)->assertOk();
        $this->assertCacheControl($response, ['public', 'max-age=31536000', 'immutable']);
        Image::assertRendered(fn (ImageSpec $spec, ImageSource $source) => $source->transform?->crop?->width === 600);

        $recropped = $edited(['crop' => ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 400], 'focalPoint' => ['x' => 0.2, 'y' => 0.3]]);
        $refocused = $edited(['crop' => ['x' => 0, 'y' => 0, 'width' => 600, 'height' => 400], 'focalPoint' => ['x' => 0.7, 'y' => 0.3]]);
        $urls = [$url, Image::for($recropped)->preset('card')->url(), Image::for($refocused)->preset('card')->url(), Image::for($this->photo)->preset('card')->url()];
        $this->assertSame($urls, array_values(array_unique($urls)));

        // Once the media is edited, its earlier URL is only served from the store.
        Image::resolver(new ArraySourceResolver($recropped));
        $this->get($urls[1])->assertOk();
        $this->get($url)->assertOk();
        $this->assertCount(2, $this->fake->renderedImages());
        $this->store->clear($recropped);
        $this->get($url)->assertNotFound();
    }

    public function test_variants_have_the_size_of_the_source_s_transform(): void
    {
        $source = new ImageSource('array', 'edited.jpg', '8c1f', width: 1200, height: 800, mime: 'image/jpeg', transform: Transform::fromArray(['crop' => ['x' => 0, 'y' => 0, 'width' => 600, 'height' => 400], 'rotate' => 90]));
        Image::resolver(new ArraySourceResolver($source));

        $variant = Image::for($source)->width(300)->signed()->variant();

        // 600×400, turned to 400×600, at a width of 300.
        $this->assertSame([300, 450], [$variant->width, $variant->height]);
        $this->get($variant->url)->assertOk();
        Image::assertRendered(fn (ImageSpec $spec, ImageSource $rendered) => $rendered->transform?->rotate === 90);
    }

    public function test_redirects_to_the_store(): void
    {
        $this->config()->set('image.delivery.serve', 'redirect');

        $response = $this->get(Image::for($this->photo)->preset('card')->url());

        $response->assertRedirect('https://bucket.test/derivatives/array/photos/2026/photo.jpg/8c1f/w800%2Car-16-9.jpg');
        $this->assertCacheControl($response, ['public', 'max-age=31536000', 'immutable']);
        $this->assertCount(1, $this->store->paths(), 'The derivative is stored before the redirect.');
    }

    public function test_private_sources_and_stores_without_urls_are_streamed_even_when_redirecting(): void
    {
        $this->config()->set('image.delivery.serve', 'redirect');

        $this->get(Image::for($this->private)->preset('card')->url())->assertOk();

        $this->app?->instance(DerivativeStore::class, new InMemoryDerivativeStore);
        $this->get(Image::for($this->photo)->preset('card')->url())->assertOk();
    }

    public function test_a_request_waits_for_the_lock_of_a_render_in_progress(): void
    {
        $this->config()->set('image.derivatives.lock.wait', 0);
        $spec = ArrayPreset::fromArray('card', self::CARD)->spec($this->photo)->withFormat(Format::Jpeg);
        $cache = $this->app?->make(CacheFactory::class)->store()->getStore();
        $this->assertInstanceOf(LockProvider::class, $cache);
        $lock = $cache->lock('folklore-image:render:'.hash('sha256', DerivativeKey::for($this->photo, $spec)->path()), 60);
        $this->assertTrue($lock->get());

        $response = $this->get(Image::for($this->photo)->preset('card')->url());

        $response->assertStatus(503);
        $this->assertSame('1', $response->headers->get('Retry-After'));
        Image::assertNothingRendered();

        $lock->release();
        $this->get(Image::for($this->photo)->preset('card')->url())->assertOk();
    }

    public function test_sources_without_a_version_are_not_immutable(): void
    {
        $source = new ImageSource('array', 'unversioned.jpg', width: 100, height: 100, mime: 'image/jpeg');
        Image::resolver(new ArraySourceResolver($source));

        $this->assertCacheControl($this->get(Image::for($source)->preset('card')->url())->assertOk(), ['public', 'max-age=31536000']);
    }

    public function test_keys_are_read_percent_encoded(): void
    {
        $source = new ImageSource('array', 'été/a b.png', '1', width: 100, height: 100, mime: 'image/png');
        Image::resolver(new ArraySourceResolver($source));
        $url = Image::for($source)->preset('card')->url();

        $this->assertStringContainsString('/array/%C3%A9t%C3%A9/a%20b.png.png', $url);
        $this->get($url)->assertOk();
        Image::assertRendered(fn (ImageSpec $spec, ImageSource $rendered) => $rendered->key === 'été/a b.png');
    }

    public function test_head_requests(): void
    {
        $this->head(Image::for($this->photo)->preset('card')->url())->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_the_route_can_have_its_own_prefix_and_be_in_a_group(): void
    {
        self::routeImage('media');
        Route::prefix('{locale}')->group(fn () => self::routeImage('pictures'));
        $path = substr(Image::for($this->photo)->preset('card')->url(), strlen('https://img.test/img/'));

        $this->get("/media/{$path}")->assertOk();
        $this->get("/fr/pictures/{$path}")->assertOk();
    }

    public function test_the_route_takes_the_projects_middleware(): void
    {
        self::routeImage('guarded')->middleware(DenyMiddleware::class);
        $path = substr(Image::for($this->photo)->preset('card')->url(), strlen('https://img.test/img/'));

        $this->get("/guarded/{$path}")->assertUnauthorized();
        $this->get("/img/{$path}")->assertOk();
    }

    /**
     * @param  TestResponse<Response>  $response
     * @param  list<string>  $directives
     */
    private function assertCacheControl(TestResponse $response, array $directives): void
    {
        $actual = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));
        sort($actual);
        sort($directives);

        $this->assertSame($directives, $actual);
    }
}
