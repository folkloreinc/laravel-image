<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature;

use DateInterval;
use Folklore\Image\Contracts\UrlCodec;
use Folklore\Image\Exceptions\InvalidImageSource;
use Folklore\Image\Exceptions\InvalidImageSpec;
use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Exceptions\SourceNotFound;
use Folklore\Image\Exceptions\UnsupportedReference;
use Folklore\Image\Facades\Image;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\ArrayPreset;
use Folklore\Image\Specs\Fit;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Specs\Transform;
use Folklore\Image\Tests\Doubles\ArraySourceResolver;
use Folklore\Image\Variants\ImageVariant;
use Illuminate\Support\Carbon;

final class PendingImageTest extends ImageTestCase
{
    private const string PHOTO = 'array://photos/2026/photo.jpg';

    private string $card;

    protected function setUp(): void
    {
        parent::setUp();

        $this->card = 'card.'.ArrayPreset::fromArray('card', self::CARD)->version();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_000));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_preset_url(): void
    {
        $image = Image::for(self::PHOTO)->preset('card');

        $this->assertSame("https://img.test/img/_/{$this->card},v-8c1f/array/photos/2026/photo.jpg.jpg", $image->url());
        $this->assertSame($image->url(), (string) $image);
        $this->assertSame('w800,ar-16-9.jpeg', $image->spec()->key());
    }

    public function test_a_preset_url_can_change_its_width_to_one_of_its_widths_and_its_format(): void
    {
        $image = Image::for(self::PHOTO)->preset('card');

        $this->assertSame("https://img.test/img/_/{$this->card},w400,v-8c1f/array/photos/2026/photo.jpg.avif", $image->width(400)->avif()->url());
        $this->assertSame("https://img.test/img/_/{$this->card},v-8c1f/array/photos/2026/photo.jpg.webp", $image->width(800)->webp()->url(), 'The preset\'s own width');
        $this->assertSame('w400,ar-16-9.avif', $image->width(400)->avif()->spec()->key());
    }

    public function test_a_preset_url_keeps_the_shape_of_the_preset_at_another_width(): void
    {
        $this->assertSame('w600,h315.jpeg', Image::for(self::PHOTO)->preset('og')->width(600)->spec()->key());
        $this->assertSame('w600,ar-1-1.jpeg', Image::for(self::PHOTO)->preset('card')->ratio('1:1')->width(600)->spec()->key());
        $this->assertSame('w600,h200.jpeg', Image::for(self::PHOTO)->preset('og')->size(600, 200)->spec()->key());
        $this->assertSame('h300,ar-16-9.jpeg', Image::for(self::PHOTO)->preset('card')->height(300)->spec()->key(), 'A height keeps the ratio');
        $this->assertSame('w500,h300.jpeg', Image::for(self::PHOTO)->preset('card')->height(300)->width(500)->spec()->key(), 'unless a width is given');
        $this->assertSame('w1200,ar-1-1.jpeg', Image::for(self::PHOTO)->preset('og')->ratio('1:1')->spec()->key(), 'A ratio keeps the width');
        $this->assertSame('h300,ar-1-1.jpeg', Image::for(self::PHOTO)->preset('og')->ratio('1:1')->height(300)->spec()->key());
    }

    public function test_other_options_make_a_free_spec_that_strict_mode_only_serves_signed(): void
    {
        $image = Image::for(self::PHOTO)->preset('card');

        foreach ([$image->width(500), $image->quality(70), $image->fit('contain'), $image->ratio(4, 5), $image->height(300)] as $free) {
            $this->assertNull($free->signed(expires: false)->imageUrl()->preset);

            try {
                $free->url();
                $this->fail('A free spec got an unsigned URL in strict mode.');
            } catch (InvalidImageUrl $exception) {
                $this->assertStringContainsString('Strict mode', $exception->getMessage());
            }
        }
    }

    public function test_free_specs_get_unsigned_urls_without_strict_mode(): void
    {
        $this->config()->set('image.strict', false);

        $this->assertSame(
            'https://img.test/img/_/w300,h200,fit-inside,v-8c1f/array/photos/2026/photo.jpg.webp',
            Image::for(self::PHOTO)->size(300, 200)->fit(Fit::Inside)->format('webp')->url(),
        );
        $this->assertSame('https://img.test/img/_/v-8c1f/array/photos/2026/photo.jpg.jpg', Image::for(self::PHOTO)->url());
    }

    public function test_a_signed_url_is_verified_by_the_signer(): void
    {
        $url = Image::for(self::PHOTO)->width(300)->signed(expires: false)->imageUrl();

        $this->assertNotNull($url->signature);
        $this->assertNull($url->expires);
        $this->assertTrue($this->signer()->verify($this->codec()->payload($url), $url->signature));
        $this->assertSame(
            "https://img.test/img/{$url->signature}/w300,v-8c1f/array/photos/2026/photo.jpg.jpg",
            Image::for(self::PHOTO)->width(300)->signed(expires: false)->url(),
        );
    }

    public function test_signed_urls_expire_after_the_default_ttl_or_when_asked(): void
    {
        $image = Image::for(self::PHOTO)->preset('card');

        $this->assertSame(1_800_003_600, $image->signed()->imageUrl()->expires);
        $this->assertSame(1_800_000_600, $image->signed(600)->imageUrl()->expires);
        $this->assertSame(1_800_086_400, $image->signed(new DateInterval('P1D'))->imageUrl()->expires);
        $this->assertSame(1_900_000_000, $image->signed(Carbon::createFromTimestamp(1_900_000_000))->imageUrl()->expires);
        $this->assertStringContainsString(',exp-1800003600/', $image->signed()->url());

        $this->config()->set('image.signing.default_ttl', null);
        $this->assertNull($image->signed()->imageUrl()->expires);
    }

    public function test_the_default_expiry_is_computed_when_the_url_is(): void
    {
        $signed = Image::for(self::PHOTO)->preset('card')->signed();
        Carbon::setTestNow(Carbon::createFromTimestamp(1_800_000_100));

        $this->assertSame(1_800_003_700, $signed->imageUrl()->expires);
    }

    public function test_private_sources_only_get_signed_urls_that_expire(): void
    {
        $url = Image::for('array://private.jpg')->preset('card')->imageUrl();

        $this->assertNotNull($url->signature);
        $this->assertSame(1_800_003_600, $url->expires);
        $this->assertSame(1_800_000_060, Image::for('array://private.jpg')->preset('card')->signed(60)->imageUrl()->expires);

        $this->assertThrows(fn () => Image::for('array://private.jpg')->preset('card')->signed(expires: false)->url(), InvalidImageUrl::class, 'give signed() an expiry');

        $this->config()->set('image.signing.default_ttl', null);
        $this->assertThrows(fn () => Image::for('array://private.jpg')->preset('card')->url(), InvalidImageUrl::class, 'default_ttl');
    }

    public function test_the_host_and_the_prefix(): void
    {
        $image = Image::for(self::PHOTO)->preset('card');
        $path = "_/{$this->card},v-8c1f/array/photos/2026/photo.jpg.jpg";

        $this->assertSame("https://other.test/img/{$path}", $image->host('https://other.test/')->url());

        Image::useHost('https://request.test');
        $this->assertSame("https://request.test/img/{$path}", $image->url());
        $this->assertSame("https://other.test/img/{$path}", $image->host('https://other.test')->url(), 'The image\'s host wins.');
        Image::useHost(null);

        $this->config()->set('image.url.host', null);
        $this->assertSame("http://localhost/img/{$path}", $image->url());

        $this->config()->set('image.url.prefix', '/media/images/');
        $this->assertSame("http://localhost/media/images/{$path}", $image->url());

        $this->config()->set('image.url.prefix', '');
        $this->assertSame("http://localhost/{$path}", $image->url());
    }

    public function test_keys_are_encoded_in_urls(): void
    {
        $this->config()->set('image.strict', false);
        Image::resolver(new ArraySourceResolver(new ImageSource('array', 'été/a b.png', mime: 'image/png')));

        $this->assertSame('https://img.test/img/_/_/array/%C3%A9t%C3%A9/a%20b.png.png', Image::for('array://été/a b.png')->url());
    }

    public function test_the_builder_is_immutable(): void
    {
        $base = Image::for(self::PHOTO)->preset('card');
        $base->width(400)->webp()->quality(50)->signed()->host('https://other.test');

        $this->assertSame("https://img.test/img/_/{$this->card},v-8c1f/array/photos/2026/photo.jpg.jpg", $base->url());
    }

    public function test_limits_are_checked_when_the_url_is_built(): void
    {
        $this->assertThrows(fn () => Image::for(self::PHOTO)->width(6000)->signed()->url(), InvalidImageSpec::class, 'over the limit');

        $this->config()->set('image.limits.max_width', null);
        $this->config()->set('image.upscale', true);
        $this->assertStringContainsString('/w6000,', Image::for(self::PHOTO)->width(6000)->signed()->url());
    }

    public function test_a_variant_has_the_dimensions_of_the_output(): void
    {
        $variant = Image::for(self::PHOTO)->preset('card')->webp()->variant();

        $this->assertSame([
            'preset' => 'card',
            'url' => "https://img.test/img/_/{$this->card},v-8c1f/array/photos/2026/photo.jpg.webp",
            'width' => 800,
            'height' => 450,
            'mime' => 'image/webp',
        ], $variant->toArray());

        $this->assertSame([1200, 800], [Image::for(self::PHOTO)->width(3000)->signed()->variant()->width, Image::for(self::PHOTO)->width(3000)->signed()->variant()->height], 'No upscaling');
        $this->assertNull(Image::for(self::PHOTO)->width(300)->signed()->variant()->preset);
    }

    public function test_a_variant_needs_the_dimensions_of_the_source(): void
    {
        $this->assertThrows(fn () => Image::for('array://unsized.png')->preset('card')->variant(), InvalidImageSource::class);
    }

    public function test_variants_are_the_widths_of_a_preset_in_each_of_its_formats_then_the_fallback(): void
    {
        $variants = Image::for(self::PHOTO)->variants('card');

        $this->assertSame([
            ['avif', 400, 225, 'w400'], ['avif', 800, 450, null], ['avif', 1200, 675, 'w1200'],
            ['webp', 400, 225, 'w400'], ['webp', 800, 450, null], ['webp', 1200, 675, 'w1200'],
            ['jpeg', 400, 225, 'w400'], ['jpeg', 800, 450, null], ['jpeg', 1200, 675, 'w1200'],
        ], $variants->map(fn (ImageVariant $variant) => [
            $variant->format->value,
            $variant->width,
            $variant->height,
            preg_match('/,(w\d+),/', $variant->url, $matches) === 1 ? $matches[1] : null,
        ])->all());
        $this->assertSame(['card'], $variants->pluck('preset')->unique()->values()->all());
        $this->assertEquals($variants, Image::for(self::PHOTO)->preset('card')->variants());
    }

    public function test_variants_of_a_preset_without_widths_or_formats_or_of_a_free_spec(): void
    {
        $this->assertSame([[1200, 630, 'image/jpeg']], Image::for(self::PHOTO)->variants('og')->map(fn (ImageVariant $v) => [$v->width, $v->height, $v->mime()])->all());
        $this->assertSame(['image/webp', 'image/webp', 'image/webp'], Image::for(self::PHOTO)->preset('card')->webp()->variants()->map(fn (ImageVariant $v) => $v->mime())->all());
        $this->assertCount(1, Image::for(self::PHOTO)->width(300)->signed()->variants());
    }

    public function test_variants_of_a_signed_image_are_signed(): void
    {
        foreach (Image::for(self::PHOTO)->preset('card')->signed()->variants() as $variant) {
            $this->assertDoesNotMatchRegularExpression('#/img/_/#', $variant->url);
            $this->assertStringContainsString(',exp-1800003600/', $variant->url);
        }
    }

    public function test_a_transform_for_one_use_is_in_the_spec_and_has_its_own_url(): void
    {
        $media = new ImageSource('array', 'media.jpg', '8c1f', width: 1200, height: 800, mime: 'image/jpeg', transform: new Transform(rotate: 90));
        Image::resolver(new ArraySourceResolver($media));
        $cover = ['base' => 'media', 'crop' => ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 450]];
        $square = ['base' => 'media', 'crop' => ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 800]];

        $coverImage = Image::for($media)->transform($cover)->preset('card');

        // The media's version is 800×1200: its top 800×450 is the left 450×800 of the original.
        $this->assertSame('c-0-0-450-800,r-90,w800,ar-16-9.jpeg', $coverImage->spec()->key());
        $urls = [
            $coverImage->signed(false)->url(),
            Image::for($media)->transform($square)->preset('card')->signed(false)->url(),
            Image::for($media)->preset('card')->url(),
        ];
        $this->assertSame($urls, array_values(array_unique($urls)));
        $this->assertStringContainsString('/c-0-0-450-800,r-90,w800,ar-16-9,v-8c1f-', $urls[0]);

        $this->expectException(InvalidImageUrl::class);
        $coverImage->url();
    }

    public function test_keys_a_renderer_doesn_t_know_change_nothing(): void
    {
        $media = new ImageSource('array', 'media.jpg', '8c1f', width: 1200, height: 800, mime: 'image/jpeg', transform: new Transform(rotate: 90));
        $transform = ['base' => 'media', 'crop' => ['x' => 0, 'y' => 0, 'width' => 800, 'height' => 450]];

        $this->assertSame(
            Image::for($media)->transform($transform)->spec()->key(),
            Image::for($media)->transform([...$transform, 'baseTransform' => ['rotate' => 180], 'tool' => ['straighten' => 2]])->spec()->key(),
        );
    }

    public function test_a_transform_over_a_source_without_one_is_a_transform_from_the_original(): void
    {
        $photo = new ImageSource('array', 'photo.jpg', '8c1f', width: 1200, height: 800, mime: 'image/jpeg');
        $transform = ['crop' => ['x' => 10, 'y' => 20, 'width' => 300, 'height' => 200], 'flipX' => true, 'focalPoint' => ['x' => 0.3, 'y' => 0.6]];

        $this->assertSame(
            Image::for($photo)->transform($transform)->width(100)->ratio('1:1')->spec()->key(),
            Image::for($photo)->transform(['base' => 'media', ...$transform])->width(100)->ratio('1:1')->spec()->key(),
        );
        $this->assertSame(
            Image::for($photo)->transform($transform)->width(100)->spec()->key(),
            Image::for($photo)->transform(['base' => 'original', ...$transform])->width(100)->spec()->key(),
        );
    }

    public function test_an_empty_transform_for_one_use_leaves_the_original_untouched(): void
    {
        $media = new ImageSource('array', 'media.jpg', '8c1f', width: 1200, height: 800, mime: 'image/jpeg', transform: new Transform(rotate: 90));

        $this->assertSame('c-0-0-1200-800,w300.jpeg', Image::for($media)->transform([])->width(300)->spec()->key());
        // Over the media's, nothing more: the media's own edits.
        $this->assertSame('r-90,w300.jpeg', Image::for($media)->transform(['base' => 'media'])->width(300)->spec()->key());
        $this->assertSame([300, 450], [Image::for($media)->width(300)->signed()->variant()->width, Image::for($media)->width(300)->signed()->variant()->height]);
        $this->assertSame([300, 200], [Image::for($media)->transform([])->width(300)->signed()->variant()->width, Image::for($media)->transform([])->width(300)->signed()->variant()->height]);
    }

    public function test_a_transform_s_base_is_media_or_original(): void
    {
        $this->expectException(InvalidImageSpec::class);

        Image::for(self::PHOTO)->transform(['base' => 'library']);
    }

    public function test_composing_over_a_source_s_transform_needs_its_dimensions(): void
    {
        $unsized = new ImageSource('array', 'unsized.jpg', '8c1f', mime: 'image/jpeg', transform: new Transform(rotate: 90));

        $this->assertSame('r-180.jpeg', Image::for($unsized)->transform(['base' => 'media', 'rotate' => 90])->spec()->key());

        $this->expectException(InvalidImageSpec::class);
        Image::for($unsized)->transform(['base' => 'media', 'crop' => ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10]]);
    }

    public function test_render_uses_the_renderer(): void
    {
        Image::fake();

        $rendered = Image::for(self::PHOTO)->preset('card')->width(400)->avif()->render();

        $this->assertSame([400, 225, Format::Avif], [$rendered->width, $rendered->height, $rendered->format]);
        Image::assertRendered(fn (ImageSpec $spec) => $spec->key() === 'w400,ar-16-9.avif');
    }

    public function test_a_source_is_its_own_reference(): void
    {
        $this->assertSame($this->photo, Image::for($this->photo)->source);
    }

    public function test_unknown_references_presets_and_formats_are_refused(): void
    {
        $this->assertThrows(fn () => Image::for(42), UnsupportedReference::class);
        $this->assertThrows(fn () => Image::for('array://missing.jpg'), SourceNotFound::class);
        $this->assertThrows(fn () => Image::for(self::PHOTO)->preset('missing'), InvalidImageSpec::class, 'Unknown preset');
        $this->assertThrows(fn () => Image::for(self::PHOTO)->format('tiff'), InvalidImageSpec::class);
    }

    private function codec(): UrlCodec
    {
        $codec = $this->app?->make(UrlCodec::class);
        $this->assertInstanceOf(UrlCodec::class, $codec);

        return $codec;
    }
}
