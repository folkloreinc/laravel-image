<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Feature\Compat;

use Folklore\Image\Compat\LegacyConfig;
use Folklore\Image\Facade as LegacyFacade;
use Folklore\Image\Facades\Image;
use Folklore\Image\ImageManager;
use Folklore\Image\ImageServiceProvider;
use Folklore\Image\ServiceProvider as LegacyServiceProvider;
use Folklore\Image\Sources\ImageSource;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Tests\Doubles\ArraySourceResolver;
use Illuminate\Support\Arr;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The deprecated 1.x API (docs/decisions/0007-compatibility-layer.md) keeps returning the URLs
 * 1.x returned, for a project that upgrades without changing its code.
 */
final class LegacyApiTest extends LegacyTestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function generatedUrls(): iterable
    {
        $readme = self::jsonFixture('urls/v1-readme.json')['generated'];
        $fixtures = [...self::jsonFixture('urls/v1.json'), ...(is_array($readme) ? $readme : [])];

        foreach ($fixtures as $index => $fixture) {
            if (is_array($fixture)) {
                $description = $fixture['description'] ?? null;
                yield (is_string($description) ? $description : '').($index >= 19 ? ' (README)' : '') => [LegacyConfig::options($fixture)];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    #[DataProvider('generatedUrls')]
    public function test_image_url_returns_the_1x_urls(array $fixture): void
    {
        $this->assertIsString($fixture['src']);
        $this->assertIsArray($fixture['filters']);
        $width = $fixture['width'];
        $height = $fixture['height'];
        $this->assertTrue($width === null || is_int($width));
        $this->assertTrue($height === null || is_int($height));

        $this->assertSame($fixture['url'], Image::url($fixture['src'], $width, $height, $fixture['filters']));
        $this->assertSame($fixture['url'], image_url($fixture['src'], $width, $height, $fixture['filters']));
    }

    public function test_a_1x_project_that_registers_its_sizes_at_boot_keeps_its_urls(): void
    {
        $this->bootSizesAs1xProjectsDo();

        $this->assertSame('/files/ab/photo-filters(large).jpg', image()->url('/files/ab/photo.jpg', ['large']));
        $this->assertSame('/files/ab/photo-filters(thumbnail).jpg', image()->url('/files/ab/photo.jpg', ['thumbnail']));
        $this->assertSame('/files/ab/photo-filters(large).jpg.webp', image()->url('/files/ab/photo.jpg', ['large', 'format' => 'webp']));
        $this->assertSame('/files/ab/photo-filters(large).jpg', $this->imageKey()->url('/files/ab/photo.jpg', ['large']));
        $this->assertSame('/files/ab/photo-filters(large).jpg', LegacyFacade::url('/files/ab/photo.jpg', ['large']));

        // The filters registered at boot are presets too.
        $this->assertSame(['width' => 1400, 'height' => 1400, 'fit' => 'inside'], Image::presets()->get('large')->spec(new ImageSource('disk', 'photo.jpg'))->toArray());
        $this->assertSame(['width' => 200, 'height' => 200], Image::presets()->get('thumbnail')->spec(new ImageSource('disk', 'photo.jpg'))->toArray());
    }

    public function test_the_template_of_the_1x_config_is_kept(): void
    {
        $this->config()->set('image.url', [
            ...$this->urlConfig(),
            'format' => '{dirname}/{filters}{basename}.{extension}',
            'filters_format' => 'filters/{filter}/',
            'filter_format' => '{key}_{value}',
            'filter_separator' => '/',
        ]);
        $this->bootSizesAs1xProjectsDo();

        $this->assertSame('/files/ab/filters/large/photo.jpg', image()->url('/files/ab/photo.jpg', ['large']));
        $this->assertSame('/files/ab/filters/facebook/photo.jpg', image()->url('/files/ab/photo.jpg', ['facebook']));
        $this->assertSame('/files/ab/filters/300x300/photo.jpg', image()->url('/files/ab/photo.jpg', 300, 300));
        $this->assertSame('/files/ab/photo.jpg', image()->url('/files/ab/photo.jpg'));
    }

    public function test_sizes_are_presets_when_image_presets_is_not_set(): void
    {
        $presets = Image::presets();
        $source = new ImageSource('disk', 'photo.jpg');

        $this->assertSame(['thumbnail', 'small', 'medium', 'large', 'bigthumbnail', 'facebook', 'square'], array_keys($presets->all()));
        $this->assertSame(['width' => 200, 'height' => 200], $presets->get('thumbnail')->spec($source)->toArray());
        $this->assertSame(['width' => 400, 'height' => 400, 'fit' => 'inside'], $presets->get('small')->spec($source)->toArray());
        $this->assertSame(['width' => 1080, 'height' => 1080, 'fit' => 'inside'], $presets->get('bigthumbnail')->spec($source)->toArray(), 'The crop key of a size is ignored, as 1.x boot code ignored it.');
        $this->assertSame(['width' => 1200, 'height' => 630], $presets->get('facebook')->spec($source)->toArray());
        $this->assertSame(['width' => 300, 'height' => 300], $presets->get('square')->spec($source)->toArray(), 'Exact sizes crop, whatever their crop key.');
        $this->assertFalse($presets->has('original'));
    }

    public function test_sizes_read_as_presets_render_as_the_filters_of_the_boot_code(): void
    {
        $log = $this->logToTestHandler();
        $versions = array_map(fn ($preset) => $preset->version(), Image::presets()->all());

        $this->bootSizesAs1xProjectsDo();

        $this->assertSame($versions, array_map(fn ($preset) => $preset->version(), Image::presets()->all()));
        $this->assertFalse($log->hasNoticeRecords(), 'The same definitions replace nothing.');
    }

    public function test_a_filter_that_replaces_a_preset_with_another_definition_is_logged(): void
    {
        $log = $this->logToTestHandler();

        Image::filter('large', ['width' => 500]);

        $this->assertTrue($log->hasNoticeThatContains("Image::filter('large') replaces the preset large"));
        $this->assertSame(['width' => 500], Image::presets()->get('large')->spec(new ImageSource('disk', 'photo.jpg'))->toArray());
    }

    public function test_sizes_are_not_presets_when_image_presets_is_set(): void
    {
        $this->config()->set('image.presets', ['card' => ['width' => 800]]);
        $this->app?->forgetInstance(PresetRegistry::class);

        $this->assertSame(['card'], array_keys(Image::presets()->all()));
    }

    public function test_app_image_url_is_the_host_of_2x_urls_not_of_1x_urls(): void
    {
        $this->config()->set('app.image_url', 'https://cdn.example.com/');
        $this->config()->set('image.strict', false);
        Image::resolver(new ArraySourceResolver(new ImageSource('array', 'photo.jpg', 'a1b2', width: 800, height: 600, mime: 'image/jpeg')));

        $this->assertStringStartsWith('https://cdn.example.com/img/', Image::for('array://photo.jpg')->preset('large')->url());
        $this->assertSame('/photo-filters(large).jpg', Image::url('photo.jpg', ['large']));

        $this->config()->set('image.url.host', 'https://img.example.com');
        $this->assertStringStartsWith('https://img.example.com/img/', Image::for('array://photo.jpg')->preset('large')->url());
    }

    public function test_the_image_key_is_the_manager_for_a_1x_config(): void
    {
        $this->assertSame(app(ImageManager::class), $this->imageKey());
        $this->assertSame(app(ImageManager::class), image());
    }

    public function test_the_route_of_a_url_gives_its_template_and_domain(): void
    {
        self::routeImageLegacy('thumbnails/{pattern}', ['as' => 'thumbnails', 'domain' => 'img.example.com', 'pattern' => ['filters_format' => '-thumb({filter})']]);

        $this->assertSame('http://img.example.com/thumbnails/files/photo-thumb(300x300).jpg', Image::url('files/photo.jpg', 300, 300, ['route' => 'thumbnails']));
    }

    public function test_deprecations_are_logged_once_per_call_site(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Image::url('photo.jpg', 300, 300);
        }
        image_url('photo.jpg', 300, 300);
        Image::filter('square', ['width' => 100, 'height' => 100, 'crop' => true]);

        $this->assertSame([
            'folklore/laravel-image: Image::url() and image_url() are deprecated: use Image::for($path)->preset($name)->url().',
            'folklore/laravel-image: Image::url() and image_url() are deprecated: use Image::for($path)->preset($name)->url().',
            'folklore/laravel-image: Image::filter() is deprecated: configure presets in image.presets.',
        ], $this->deprecations());
    }

    public function test_filters_written_against_imagine_are_ignored(): void
    {
        Image::filter('sepia', fn (mixed $image) => $image);
        Image::filter('circle', 'App\Filters\Circle');

        $this->assertFalse(Image::presets()->has('sepia'));
        $this->assertFalse(Image::presets()->has('circle'));
        $this->assertStringContainsString('The 1.x filter sepia is written against Imagine', $this->deprecations()[0]);
        $this->assertSame('/photo-filters(sepia).jpg', Image::url('photo.jpg', ['sepia']));
    }

    public function test_the_1x_service_provider_and_facade_still_load(): void
    {
        $this->app?->register(LegacyServiceProvider::class);

        $this->assertNotNull($this->app?->getProvider(ImageServiceProvider::class));
        $this->assertSame('/photo-filters(300x300).jpg', LegacyFacade::url('photo.jpg', 300, 300));
    }

    public function test_image_with_a_path_no_longer_makes_an_image(): void
    {
        $this->expectException(\BadMethodCallException::class);

        image('photo.jpg', ['width' => 300]);
    }

    /**
     * What the AppServiceProvider of 1.x projects does at boot: each named size becomes a filter,
     * cropped when it has a width and a height, whatever its crop key.
     */
    private function bootSizesAs1xProjectsDo(): void
    {
        $sizes = $this->config()->get('image.sizes');
        $this->assertIsArray($sizes);

        foreach ($sizes as $size) {
            $this->assertIsArray($size);
            $data = Arr::except($size, ['id']);
            if ($data === []) {
                continue;
            }
            $filter = [];
            if (isset($size['maxWidth'])) {
                $filter['width'] = $size['maxWidth'];
            }
            if (isset($size['maxHeight'])) {
                $filter['height'] = $size['maxHeight'];
            }
            if (isset($size['width'])) {
                $filter['width'] = $size['width'];
            }
            if (isset($size['height'])) {
                $filter['height'] = $size['height'];
            }
            if (isset($size['width'], $size['height'])) {
                $filter['crop'] = true;
            }
            $this->assertIsString($size['id']);
            $this->imageKey()->filter($size['id'], $filter);
        }
    }

    private function logToTestHandler(): TestHandler
    {
        $handler = new TestHandler;
        $this->config()->set('logging.default', 'test');
        $this->config()->set('logging.channels.test', ['driver' => 'custom', 'via' => fn () => new Logger('test', [$handler])]);

        return $handler;
    }

    /**
     * What `app('image')` and `$app['image']` resolve to.
     */
    private function imageKey(): ImageManager
    {
        $image = $this->app?->make($this->app->getAlias('image'));
        $this->assertInstanceOf(ImageManager::class, $image);

        return $image;
    }

    /**
     * @return array<string, mixed>
     */
    private function urlConfig(): array
    {
        $url = $this->config()->get('image.url');
        $this->assertIsArray($url);

        /** @var array<string, mixed> $url */
        return $url;
    }
}
