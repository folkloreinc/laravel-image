<?php

namespace Folklore\Image\Tests\Unit\Filters;

use Folklore\Image\Filters\Grayscale as GrayscaleFilter;
use Folklore\Image\Tests\Mocks\EffectsMock;
use Folklore\Image\Tests\Mocks\ImageMock;
use Folklore\Image\Tests\TestCase;

/**
 * @coversDefaultClass Folklore\Image\Filters\Grayscale
 */
class GrayscaleTest extends TestCase
{
    protected $filter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->filter = new GrayscaleFilter;
    }

    /**
     * Test the apply method
     *
     * @test
     *
     * @covers ::apply
     */
    public function test_apply()
    {
        $effectsMock = new EffectsMock;
        $imageMock = new ImageMock($effectsMock);

        $this->filter->apply($imageMock);

        $this->assertEquals('effects', $imageMock->called);
        $this->assertEquals('grayscale', $effectsMock->called);
    }
}
