<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Urls;

use Folklore\Image\Compat\LegacyConfig;
use Folklore\Image\Compat\LegacyFilters;
use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Specs\ArrayPreset;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\PresetRegistry;
use Folklore\Image\Tests\TestCase;
use Folklore\Image\Urls\ImageUrl;
use Folklore\Image\Urls\PresetReference;
use Folklore\Image\Urls\V1Codec;
use Illuminate\Container\Container;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * V1Codec decodes the 1.x URLs of tests/fixtures/urls (docs/decisions/0004-specs-and-urls.md),
 * with the default 1.x template and with a project's own.
 */
final class V1CodecTest extends TestCase
{
    /** A 1.x project's template: filters as directories before the file name. */
    private const array DIRECTORIES = [
        'format' => '{dirname}/{filters}{basename}.{extension}',
        'filters_format' => 'filters/{filter}/',
        'filter_format' => '{key}_{value}',
        'filter_separator' => '/',
        'placeholders_patterns' => [
            'host' => '(.*?)?',
            'dirname' => '([A-Za-z0-9_\-\/]+?)?',
            'basename' => '([A-Za-z0-9_\-]+?)',
            'filename' => '([A-Za-z0-9_\-\.]+)',
            'extension' => '(jpeg|jpg|gif|png)',
        ],
    ];

    private PresetRegistry $presets;

    private LegacyFilters $filters;

    protected function setUp(): void
    {
        parent::setUp();

        $this->presets = new PresetRegistry(new Container);
        $this->filters = new LegacyFilters($this->presets);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function parsedFixtures(): iterable
    {
        foreach (self::jsonFixture('urls/v1.json') as $fixture) {
            $description = is_array($fixture) ? ($fixture['description'] ?? null) : null;
            if (is_array($fixture) && is_array($fixture['parsed'] ?? null) && is_string($description)) {
                yield $description => [LegacyConfig::options($fixture)];
            }
        }
    }

    /**
     * @param  array<string, mixed>  $fixture
     */
    #[DataProvider('parsedFixtures')]
    public function test_it_parses_the_1x_urls_as_1x_did(array $fixture): void
    {
        $this->assertIsString($fixture['url']);
        $parsed = $this->codec()->parse(ltrim($fixture['url'], '/'));

        $this->assertIsArray($fixture['parsed']);
        $this->assertIsString($fixture['parsed']['path']);
        $this->assertSame(ltrim($fixture['parsed']['path'], '/'), ltrim($parsed['path'], '/'));
        $this->assertEquals($fixture['parsed']['filters'], $parsed['filters']);
    }

    /**
     * @return iterable<string, array{string, string, string, Format}>
     */
    public static function decodedUrls(): iterable
    {
        yield 'no filters' => ['path/to/image.jpg', 'path/to/image.jpg', '_.jpeg', Format::Jpeg];
        yield 'a box: inside' => ['path/to/image-filters(300x300).jpg', 'path/to/image.jpg', 'w300,h300,fit-inside.jpeg', Format::Jpeg];
        yield 'a width' => ['path/to/image-filters(300x_).jpg', 'path/to/image.jpg', 'w300.jpeg', Format::Jpeg];
        yield 'a height' => ['path/to/image-filters(_x200).jpg', 'path/to/image.jpg', 'h200.jpeg', Format::Jpeg];
        yield 'a crop' => ['path/to/image-filters(300x200-crop).jpg', 'path/to/image.jpg', 'w300,h200.jpeg', Format::Jpeg];
        yield 'a crop position' => ['path/to/image-filters(300x200-crop(top_left)).jpg', 'path/to/image.jpg', 'w300,h200,fp-0-0.jpeg', Format::Jpeg];
        yield 'a rotation and an operation' => ['path/to/image-filters(grayscale-rotate(90)).jpg', 'path/to/image.jpg', 'r-90,grayscale.jpeg', Format::Jpeg];
        yield 'a negative rotation' => ['path/to/image-filters(300x200-rotate(-90)-grayscale).jpg', 'path/to/image.jpg', 'r-270,w300,h200,fit-inside,grayscale.jpeg', Format::Jpeg];
        yield 'an operation with a value' => ['path/to/image-filters(colorize(ff0000,RGB)).jpg', 'path/to/image.jpg', 'colorize-ff0000-rgb.jpeg', Format::Jpeg];
        yield 'a format extension' => ['path/to/image-filters(300x_).jpg.webp', 'path/to/image.jpg', 'w300.webp', Format::Webp];
        yield 'a .jpeg source' => ['uploads/photo-filters(640x_).jpeg', 'uploads/photo.jpeg', 'w640.jpeg', Format::Jpeg];
        yield 'a HEIC source, served as JPEG' => ['uploads/photo-filters(640x_).heic', 'uploads/photo.heic', 'w640.jpeg', Format::Jpeg];
        yield 'a PNG at the root' => ['image-filters(100x100).png', 'image.png', 'w100,h100,fit-inside.png', Format::Png];
        yield 'a percent-encoded path' => ['my%20photos/photo-filters(300x_).jpg', 'my photos/photo.jpg', 'w300.jpeg', Format::Jpeg];
    }

    #[DataProvider('decodedUrls')]
    public function test_it_decodes_1x_urls_into_specs(string $path, string $key, string $spec, Format $format): void
    {
        $url = $this->codec()->decode($path);

        $this->assertSame(['public', $key, $format], [$url->resolver, $url->key, $url->format]);
        $this->assertNotNull($url->spec);
        $this->assertSame($spec, $url->spec->key());
        $this->assertNull($url->preset);
        $this->assertNull($url->version);
    }

    public function test_named_filters_are_expanded_as_1x_did(): void
    {
        $this->filters->register('thumbnail', ['width' => 300, 'height' => 300, 'crop' => true]);
        $this->filters->register('bw', ['grayscale']);

        $this->assertSame(['width' => 300, 'height' => 300, 'crop' => true, 'grayscale' => true], $this->codec()->parse('image-filters(thumbnail-bw).jpg')['filters']);
        $this->assertSame('w300,h300,grayscale.jpeg', $this->codec()->decode('image-filters(thumbnail-bw).jpg')->spec?->key());
    }

    public function test_a_name_that_is_a_preset_renders_the_preset(): void
    {
        $this->presets->register(ArrayPreset::fromArray('large', ['width' => 1400, 'height' => 1400, 'fit' => 'inside']));

        $this->assertSame('w1400,h1400,fit-inside.jpeg', $this->codec()->decode('image-filters(large).jpg')->spec?->key());
        $this->assertSame('w1400,h1400,fit-inside,grayscale.webp', $this->codec()->decode('image-filters(large-grayscale).jpg.webp')->spec?->key());
    }

    public function test_an_unknown_name_is_an_operation_the_renderer_refuses(): void
    {
        $this->assertSame('bw,small.jpeg', $this->codec()->decode('path/to/image-filters(small-bw).jpg')->spec?->key());
    }

    public function test_a_project_template_with_filters_as_directories(): void
    {
        $this->filters->register('large', ['width' => 1400, 'height' => 1400]);
        $codec = $this->codec(self::DIRECTORIES);

        $this->assertSame(['path' => 'files/ab/cd/image.jpg', 'filters' => ['width' => 1400, 'height' => 1400], 'format' => null], $codec->parse('files/ab/cd/filters/large/image.jpg'));
        $this->assertSame(['path' => 'files/ab/cd/image.jpg', 'filters' => ['width' => 300, 'height' => 300, 'crop' => true], 'format' => null], $codec->parse('files/ab/cd/filters/300x300/crop/image.jpg'));
        $this->assertSame(['path' => 'files/ab/cd/image.jpg', 'filters' => [], 'format' => null], $codec->parse('files/ab/cd/image.jpg'));
        $this->assertSame('w1400,h1400,fit-inside.jpeg', $codec->decode('files/ab/cd/filters/large/image.jpg')->spec?->key());

        $this->assertSame('/files/ab/cd/filters/large/image.jpg', $codec->make('/files/ab/cd/image.jpg', ['large']));
        $this->assertSame('/files/ab/cd/filters/300x300/crop/image.jpg', $codec->make('/files/ab/cd/image.jpg', ['width' => 300, 'height' => 300, 'crop' => true]));
        $this->assertSame('/files/ab/cd/image.jpg', $codec->make('/files/ab/cd/image.jpg'));
        $this->assertSame('/image.jpg', $codec->make('image.jpg'));
    }

    public function test_the_route_pattern_matches_1x_urls_only(): void
    {
        $default = '#'.$this->codec()->pattern().'#';
        $this->assertMatchesRegularExpression($default, 'uploads/photo-filters(300x300-crop).jpg');
        $this->assertMatchesRegularExpression($default, 'uploads/photo-filters(800x_).jpg.webp');
        $this->assertMatchesRegularExpression($default, 'uploads/photo.jpg');
        $this->assertDoesNotMatchRegularExpression($default, 'uploads/photo.txt');
        $this->assertDoesNotMatchRegularExpression($default, 'build/app.js');

        $directories = '#'.$this->codec(self::DIRECTORIES)->pattern().'#';
        $this->assertMatchesRegularExpression($directories, 'files/ab/cd/filters/large/image.jpg');
        $this->assertDoesNotMatchRegularExpression($directories, 'files/ab/cd/image.webp');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'not an image' => ['uploads/photo.txt'];
        yield 'a malformed filter' => ['uploads/photo-filters(rotate(9)0)).jpg'];
        yield 'outside the source' => ['uploads/../../photo-filters(300x300).jpg'];
        yield 'a rotation 2.x does not do' => ['uploads/photo-filters(rotate(45)).jpg'];
        yield 'an unknown crop position' => ['uploads/photo-filters(300x300-crop(middle)).jpg'];
        yield 'an argument with a dot' => ['uploads/photo-filters(gamma(1.2)).jpg'];
    }

    #[DataProvider('invalidPaths')]
    public function test_it_refuses_what_it_cant_decode(string $path): void
    {
        $this->expectException(InvalidImageUrl::class);

        $this->codec()->decode($path);
    }

    public function test_it_encodes_urls_in_the_1x_syntax(): void
    {
        $codec = $this->codec();

        foreach (['path/to/image-filters(300x200-crop).jpg', 'path/to/image-filters(300x_).jpg.webp', 'path/to/image-filters(grayscale).png', 'path/to/image.jpg'] as $path) {
            $this->assertSame($path, $codec->encode($codec->decode($path)));
        }

        $this->assertSame('photo-filters(large).jpg', $codec->encode(ImageUrl::forPreset('public', 'photo.jpg', new PresetReference('large', '3f9a1c'), Format::Jpeg)));
    }

    public function test_route_options_replace_the_template(): void
    {
        $codec = $this->codec()->withOptions(['filters_format' => '-f({filter})'], 'cloud');

        $this->assertSame('cloud', $codec->decode('photo-f(300x_).jpg')->resolver);
        $this->assertSame('/photo-f(300x_).jpg', $codec->make('photo.jpg', ['width' => 300]));
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function codec(array $options = []): V1Codec
    {
        return new V1Codec($this->filters, 'public', $options);
    }
}
