<?php

namespace Folklore\Image\Tests\Feature;

use Folklore\Image\Tests\TestCase;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\Color\ColorInterface;
use Imagine\Image\Point;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images are rotated and flipped according to their EXIF orientation.
 *
 * The fixtures in `orientation/` hold the same 80×40 image, stored in each of the
 * eight EXIF orientations: upright, its top-left quarter is red, top-right green,
 * bottom-left blue and bottom-right yellow.
 */
class AutoOrientTest extends TestCase
{
    protected const UPRIGHT = [
        [10, 5, [255, 0, 0]],
        [70, 5, [0, 255, 0]],
        [10, 35, [0, 0, 255]],
        [70, 35, [255, 255, 0]],
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

    public function test_make_turns_every_orientation_upright()
    {
        foreach (range(1, 8) as $orientation) {
            $image = app('image')->make('orientation/orientation-'.$orientation.'.jpg');

            $this->assertUpright($image, 80, 40, 'Orientation '.$orientation);
        }
    }

    public function test_filters_apply_to_the_upright_image()
    {
        $image = app('image')->make('orientation/orientation-6.jpg', [
            'width' => 40,
            'height' => 40,
            'crop' => true,
        ]);

        // A centered square crop of the upright image: red and green on top.
        $this->assertSame(40, $image->getSize()->getWidth());
        $this->assertSame(40, $image->getSize()->getHeight());
        $this->assertColor([255, 0, 0], $image, 5, 5);
        $this->assertColor([0, 255, 0], $image, 35, 5);
        $this->assertColor([0, 0, 255], $image, 5, 35);
        $this->assertColor([255, 255, 0], $image, 35, 35);
    }

    public function test_a_route_serves_resized_images_upright()
    {
        $this->app['router']->image('orient/{pattern}', ['as' => 'image.orient', 'cache' => false]);

        foreach ([3, 6, 8] as $orientation) {
            $response = $this->get('/orient/orientation/orientation-'.$orientation.'-filters(40x20).jpg');
            $response->assertStatus(200);

            $this->assertUpright($this->imageFromResponse($response->baseResponse), 40, 20, 'Orientation '.$orientation);
        }
    }

    public function test_auto_orient_can_be_turned_off_in_the_config()
    {
        $this->app['config']->set('image.auto_orient', false);

        $image = app('image')->make('orientation/orientation-6.jpg');

        $this->assertSame(40, $image->getSize()->getWidth());
        $this->assertSame(80, $image->getSize()->getHeight());
    }

    public function test_auto_orient_can_be_turned_off_in_make()
    {
        $image = app('image')->make('orientation/orientation-6.jpg', ['auto_orient' => false]);
        $this->assertSame(40, $image->getSize()->getWidth());

        $this->app['config']->set('image.auto_orient', false);
        $image = app('image')->make('orientation/orientation-6.jpg', ['auto_orient' => true]);
        $this->assertSame(80, $image->getSize()->getWidth());
    }

    public function test_the_url_cannot_turn_auto_orient_off()
    {
        $this->app['router']->image('orient/{pattern}', ['as' => 'image.orient', 'cache' => false]);

        $response = $this->get('/orient/orientation/orientation-6-filters(auto_orient(false)-40x20).jpg');
        $response->assertStatus(200);

        $this->assertUpright($this->imageFromResponse($response->baseResponse), 40, 20);
    }

    public function test_a_route_can_turn_auto_orient_off()
    {
        $this->app['router']->image('stored/{pattern}', ['as' => 'image.stored', 'cache' => false, 'auto_orient' => false]);

        $response = $this->get('/stored/orientation/orientation-6-filters(20x40).jpg');
        $response->assertStatus(200);

        $image = $this->imageFromResponse($response->baseResponse);
        $this->assertSame(20, $image->getSize()->getWidth());
        $this->assertSame(40, $image->getSize()->getHeight());
    }

    public function test_open_keeps_the_stored_orientation()
    {
        $image = app('image')->open('orientation/orientation-6.jpg');

        $this->assertSame(40, $image->getSize()->getWidth());
        $this->assertSame(80, $image->getSize()->getHeight());
    }

    public function test_imagick_output_does_not_keep_the_orientation_tag()
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('The imagick extension is not installed.');
        }

        $this->app['config']->set('image.driver', 'imagick');
        foreach (['image', 'image.imagine', 'image.imagine_manager', 'image.source'] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $image = app('image')->make('orientation/orientation-6.jpg');
        $this->assertUpright($image, 80, 40);

        $data = $image->get('jpg');
        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($data));
        $this->assertContains($exif['Orientation'] ?? 1, [0, 1], 'A viewer would rotate the upright image again.');
    }

    protected function assertUpright(ImageInterface $image, int $width, int $height, string $message = ''): void
    {
        $this->assertSame($width, $image->getSize()->getWidth(), $message);
        $this->assertSame($height, $image->getSize()->getHeight(), $message);

        foreach (self::UPRIGHT as [$x, $y, $expected]) {
            $this->assertColor($expected, $image, (int) ($x * $width / 80), (int) ($y * $height / 40), $message);
        }
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
            $this->assertEqualsWithDelta($value, $actual[$channel], 40, trim($message.' at '.$x.','.$y.': '.implode(',', $actual)));
        }
    }

    protected function imageFromResponse($response): ImageInterface
    {
        $this->assertInstanceOf(StreamedResponse::class, $response);
        ob_start();
        $response->sendContent();

        return app('image.imagine')->load(ob_get_clean());
    }
}
