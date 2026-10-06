<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Point;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Derivatives are converted to sRGB and stripped of their metadata.
 *
 * - `metadata/gps.jpg` has EXIF with a camera make and GPS coordinates.
 * - `metadata/adobe-rgb.jpg` has an embedded Adobe RGB (1998) profile and four 40×40
 *   patches. The expected sRGB values of the second and third patches come from
 *   LittleCMS (relative colorimetric).
 */
class MetadataTest extends TestCase
{
    protected const ADOBE_RGB_PATCHES = [
        // [x, y, stored Adobe RGB value, sRGB value]
        [60, 20, [180, 90, 60], [205, 90, 56]],
        [100, 20, [70, 110, 170], [37, 110, 174]],
    ];

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('image.routes', [
            'map' => null,
            'domain' => null,
            'namespace' => null,
            'middleware' => [],
            'controller' => '\Folklore\Image\Http\ImageController@serve',
            'pattern_name' => 'image_pattern',
            'cache_middleware' => 'image.middleware.cache',
        ]);
    }

    public function test_derivatives_have_no_exif_with_every_driver()
    {
        foreach ($this->drivers() as $driver) {
            $this->useDriver($driver);

            foreach ([[], ['width' => 60, 'height' => 40], ['grayscale' => true]] as $options) {
                $data = app('image')->make('metadata/gps.jpg', $options)->get('jpg');
                $message = $driver.' with '.json_encode($options);

                $this->assertNoExif($data, $message);
            }
        }
    }

    public function test_a_route_serves_images_without_exif()
    {
        $this->app['router']->image('meta/{pattern}', ['as' => 'image.meta', 'cache' => false]);

        foreach ($this->drivers() as $driver) {
            $this->useDriver($driver);

            foreach (['metadata/gps-filters(grayscale).jpg', 'metadata/gps-filters(60x40).jpg'] as $url) {
                $response = $this->get('/meta/'.$url);
                $response->assertStatus(200);

                $this->assertNoExif($this->contentFromResponse($response->baseResponse), $driver.' '.$url);
            }
        }
    }

    public function test_metadata_can_be_kept()
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('The imagick extension is not installed.');
        }

        $this->app['config']->set('image.strip_metadata', false);
        $this->useDriver('imagick');

        $data = app('image')->make('metadata/gps.jpg', ['grayscale' => true])->get('jpg');
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($data), null, true);

        $this->assertIsArray($exif);
        $this->assertArrayHasKey('GPS', $exif);

        $image = app('image')->make('metadata/adobe-rgb.jpg');
        $this->assertNotEmpty($image->getImagick()->getImageProfiles('icc', false), 'The profile is kept with the unconverted pixels.');
    }

    public function test_imagick_converts_colors_to_srgb()
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('The imagick extension is not installed.');
        }

        $this->useDriver('imagick');

        foreach ([[], ['width' => 160, 'height' => 40]] as $options) {
            $data = app('image')->make('metadata/adobe-rgb.jpg', $options)->get('png');
            $image = app('image.imagine')->load($data);
            $message = json_encode($options);

            $this->assertEmpty($image->getImagick()->getImageProfiles('icc', false), $message);
            foreach (self::ADOBE_RGB_PATCHES as [$x, $y, $stored, $srgb]) {
                $this->assertColor($srgb, $image, $x, $y, $message);
            }
        }
    }

    public function test_metadata_is_kept_when_turned_off_without_converting_colors()
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('The imagick extension is not installed.');
        }

        $this->app['config']->set('image.strip_metadata', false);
        $this->useDriver('imagick');

        $image = app('image')->make('metadata/adobe-rgb.jpg');

        foreach (self::ADOBE_RGB_PATCHES as [$x, $y, $stored]) {
            $this->assertColor($stored, $image, $x, $y);
        }
    }

    /**
     * The drivers installed here: gd always, imagick and gmagick when available.
     */
    protected function drivers(): array
    {
        return array_values(array_filter([
            'gd',
            extension_loaded('imagick') ? 'imagick' : null,
            extension_loaded('gmagick') ? 'gmagick' : null,
        ]));
    }

    protected function useDriver(string $driver): void
    {
        $this->app['config']->set('image.driver', $driver);
        foreach (['image', 'image.imagine', 'image.imagine_manager', 'image.source'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
    }

    protected function assertNoExif(string $data, string $message): void
    {
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($data), null, true);
        $exif = is_array($exif) ? $exif : [];

        $this->assertArrayNotHasKey('GPS', $exif, $message);
        $this->assertArrayNotHasKey('Make', $exif['IFD0'] ?? [], $message);
        $this->assertArrayNotHasKey('ImageDescription', $exif['IFD0'] ?? [], $message);
    }

    protected function assertColor(array $expected, ImageInterface $image, int $x, int $y, string $message = ''): void
    {
        $color = $image->getColorAt(new Point($x, $y));
        $actual = [
            $color->getValue(ColorInterface::COLOR_RED),
            $color->getValue(ColorInterface::COLOR_GREEN),
            $color->getValue(ColorInterface::COLOR_BLUE),
        ];

        foreach ($expected as $channel => $value) {
            $this->assertEqualsWithDelta($value, $actual[$channel], 6, trim($message.' at '.$x.','.$y.': '.implode(',', $actual)));
        }
    }

    protected function contentFromResponse($response): string
    {
        $this->assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();

        return ob_get_clean();
    }
}
