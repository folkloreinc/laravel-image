<?php

namespace Folklore\Image\Tests\Unit\Http;

use Folklore\Image\Http\ImageResponse;
use Folklore\Image\Tests\TestCase;

/**
 * @coversDefaultClass Folklore\Image\Http\ImageResponse
 */
class ImageResponseTest extends TestCase
{
    protected $response;

    protected function setUp(): void
    {
        parent::setUp();

        $this->response = new ImageResponse;
    }

    /**
     * Test sending the image from an Image class
     *
     * @test
     *
     * @covers ::create
     */
    public function test_create()
    {
        $image = app('image')->open('image.jpg');
        $response = ImageResponse::create($image, 200, [
            'Content-Type' => 'image/png',
        ]);
        $this->assertEquals($image, $response->getImage());
        $this->assertEquals(200, $response->status());
        $this->assertEquals('image/png', $response->headers->get('Content-Type'));
    }

    /**
     * Test sending the image from an Image class
     *
     * @test
     *
     * @covers ::sendImage
     * @covers ::getContent
     */
    public function test_send_content_image()
    {
        $image = app('image')->open('image.jpg');
        $originalContent = $image->get($this->response->getFormat(), [
            'jpeg_quality' => $this->response->getQuality(),
        ]);
        $this->response->setImage($image);
        ob_start();
        $this->response->sendContent();
        $content = ob_get_clean();
        $this->assertEquals($originalContent, $content);
    }

    /**
     * Test sending the image from an image path
     *
     * @test
     *
     * @covers ::sendImage
     * @covers ::sendImageFromPath
     * @covers ::getContent
     */
    public function test_send_content_image_path()
    {
        $path = public_path('image.jpg');
        $originalContent = file_get_contents($path);
        $this->response->setImagePath($path);
        ob_start();
        $this->response->sendContent();
        $content = ob_get_clean();
        $this->assertEquals($originalContent, $content);
    }

    /**
     * Test getting the content fo the image
     *
     * @test
     *
     * @covers ::getContent
     */
    public function test_get_content()
    {
        $image = app('image')->open('image.jpg');
        $content = $image->get($this->response->getFormat(), [
            'jpeg_quality' => $this->response->getQuality(),
        ]);
        $this->response->setImage($image);
        $this->assertEquals($content, $this->response->getContent());
    }

    /**
     * Test get and set image
     *
     * @test
     *
     * @covers ::setImage
     * @covers ::getImage
     */
    public function test_get_image()
    {
        $value = app('image')->open('image.jpg');
        $this->response->setImage($value);
        $this->assertEquals($value, $this->response->getImage());
    }

    /**
     * Test get and set image path
     *
     * @test
     *
     * @covers ::setImagePath
     * @covers ::getImagePath
     */
    public function test_get_image_path()
    {
        $value = public_path('image.jpg');
        $this->response->setImagePath($value);
        $this->assertEquals($value, $this->response->getImagePath());
    }

    /**
     * Test get and set format
     *
     * @test
     *
     * @covers ::format
     * @covers ::setFormat
     * @covers ::getFormat
     * @covers ::getMimeFromFormat
     */
    public function test_get_format()
    {
        $value = 'jpg';
        $this->response->setFormat($value);
        $this->assertEquals($value, $this->response->getFormat());
        $this->assertEquals('image/jpeg', $this->response->headers->get('Content-type'));

        $value = 'png';
        $this->response->format($value);
        $this->assertEquals($value, $this->response->getFormat());
        $this->assertEquals('image/png', $this->response->headers->get('Content-type'));
    }

    /**
     * Test get and set quality
     *
     * @test
     *
     * @covers ::quality
     * @covers ::setQuality
     * @covers ::getQuality
     */
    public function test_get_quality()
    {
        $value = 90;
        $this->response->setQuality($value);
        $this->assertEquals($value, $this->response->getQuality());

        $value = 90;
        $this->response->quality($value);
        $this->assertEquals($value, $this->response->getQuality());
    }

    /**
     * Test setting expires in
     *
     * @test
     *
     * @covers ::expiresIn
     * @covers ::setExpiresIn
     */
    public function test_expires_in()
    {
        $responseMock = $this->getMockBuilder(ImageResponse::class)
            ->onlyMethods(['setMaxAge', 'setExpires'])
            ->getMock();

        $expires = 3600;
        $expiresDate = new \DateTime;
        $expiresDate->setTimestamp(time() + $expires);
        $responseMock->expects($this->exactly(2))
            ->method('setMaxAge')
            ->with($this->equalTo($expires));
        $responseMock->expects($this->exactly(2))
            ->method('setExpires')
            ->with($this->callback(function ($date) use ($expiresDate) {
                return abs($date->getTimestamp() - $expiresDate->getTimestamp()) < 10;
            }));

        $responseMock->setExpiresIn($expires);
        $responseMock->expiresIn($expires);
    }
}
