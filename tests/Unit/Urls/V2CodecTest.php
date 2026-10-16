<?php

declare(strict_types=1);

namespace Folklore\Image\Tests\Unit\Urls;

use Folklore\Image\Exceptions\InvalidImageUrl;
use Folklore\Image\Specs\Format;
use Folklore\Image\Specs\ImageSpec;
use Folklore\Image\Tests\TestCase;
use Folklore\Image\Urls\ImageUrl;
use Folklore\Image\Urls\PresetReference;
use Folklore\Image\Urls\V2Codec;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Runs tests/fixtures/urls/v2.json, which settles the v2 URL syntax
 * (docs/decisions/0009-v2-url-syntax.md).
 */
final class V2CodecTest extends TestCase
{
    /**
     * @return array<string, array{string, array<mixed>}>
     */
    public static function validUrls(): array
    {
        $cases = [];
        foreach (self::fixtures('valid') as $case) {
            self::assertIsString($case['path']);
            self::assertIsArray($case['url']);
            $cases[self::description($case)] = [$case['path'], $case['url']];
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidUrls(): array
    {
        $cases = [];
        foreach (self::fixtures('invalid') as $case) {
            self::assertIsString($case['path']);
            $cases[self::description($case)] = [$case['path']];
        }

        return $cases;
    }

    /**
     * @param  array<mixed>  $expected
     */
    #[DataProvider('validUrls')]
    public function test_a_path_decodes_to_its_url(string $path, array $expected): void
    {
        $this->assertSame($expected, (new V2Codec)->decode($path)->toArray());
    }

    /**
     * @param  array<mixed>  $url
     */
    #[DataProvider('validUrls')]
    public function test_a_url_encodes_to_its_path(string $path, array $url): void
    {
        $codec = new V2Codec;
        $imageUrl = $this->imageUrl($url);

        $this->assertSame($path, $codec->encode($imageUrl));
        $this->assertSame(substr($path, strpos($path, '/') + 1), $codec->payload($imageUrl), 'The payload is the path after the signature.');
    }

    #[DataProvider('invalidUrls')]
    public function test_an_invalid_path_is_refused(string $path): void
    {
        $this->expectException(InvalidImageUrl::class);

        (new V2Codec)->decode($path);
    }

    public function test_options_given_in_any_order_give_the_same_url(): void
    {
        $codec = new V2Codec;
        $first = ImageUrl::forSpec('disk', 'a.jpg', ImageSpec::fromArray(['width' => 300, 'operations' => ['grayscale', 'blur' => 5], 'format' => 'webp']));
        $second = ImageUrl::forSpec('disk', 'a.jpg', ImageSpec::fromArray(['format' => 'webp', 'operations' => ['blur' => '5', 'grayscale'], 'width' => 300]));

        $this->assertSame('_/w300,blur-5,grayscale/disk/a.jpg.webp', $codec->encode($first));
        $this->assertSame($codec->encode($first), $codec->encode($second));
    }

    public function test_every_spec_of_the_spec_fixtures_round_trips(): void
    {
        $codec = new V2Codec;
        $specs = self::jsonFixture('specs.json');
        $this->assertIsArray($specs['valid']);

        foreach ($specs['valid'] as $case) {
            $this->assertIsArray($case);
            $this->assertIsArray($case['options']);
            $spec = ImageSpec::fromArray($case['options'])->withFormat(Format::Webp);

            foreach (['photo.jpg', 'uploads/été 2026/photo (1).jpg', 'med_01JAB3QX'] as $key) {
                $url = ImageUrl::forSpec('disk', $key, $spec, 'v1');
                $path = $codec->encode($url);

                $this->assertSame($url->toArray(), $codec->decode($path)->toArray(), $path);
                $this->assertSame($path, $codec->encode($codec->decode($path)));
            }
        }
    }

    public function test_a_url_that_expires_is_signed(): void
    {
        $url = ImageUrl::forSpec('disk', 'a.jpg', new ImageSpec(width: 300, format: Format::Jpeg), expires: 1767225600);

        $this->assertSame('w300,exp-1767225600/disk/a.jpg.jpg', (new V2Codec)->payload($url), 'It can be signed.');

        $this->expectException(InvalidImageUrl::class);
        (new V2Codec)->encode($url);
    }

    /**
     * @param  array<mixed>  $url
     */
    private function imageUrl(array $url): ImageUrl
    {
        $this->assertIsString($url['resolver']);
        $this->assertIsString($url['key']);
        $this->assertIsString($url['format']);
        $version = $url['version'];
        $this->assertTrue($version === null || is_string($version));
        $expires = $url['expires'];
        $this->assertTrue($expires === null || is_int($expires));
        $signature = $url['signature'];
        $this->assertTrue($signature === null || is_string($signature));

        if (is_array($url['preset'])) {
            $this->assertIsString($url['preset']['name']);
            $this->assertIsString($url['preset']['version']);
            $width = $url['width'];
            $this->assertTrue($width === null || is_int($width));

            return ImageUrl::forPreset(
                $url['resolver'],
                $url['key'],
                new PresetReference($url['preset']['name'], $url['preset']['version']),
                Format::from($url['format']),
                $width,
                $version,
                $expires,
            )->withSignature($signature);
        }

        $this->assertIsArray($url['spec']);

        return ImageUrl::forSpec($url['resolver'], $url['key'], ImageSpec::fromArray($url['spec']), $version, $expires)->withSignature($signature);
    }

    /**
     * @return list<array<mixed>>
     */
    private static function fixtures(string $group): array
    {
        $fixtures = self::jsonFixture('urls/v2.json');
        self::assertIsArray($fixtures[$group]);

        $cases = [];
        foreach ($fixtures[$group] as $case) {
            self::assertIsArray($case);
            $cases[] = $case;
        }

        return $cases;
    }

    /**
     * @param  array<mixed>  $case
     */
    private static function description(array $case): string
    {
        self::assertIsString($case['description']);

        return $case['description'];
    }
}
